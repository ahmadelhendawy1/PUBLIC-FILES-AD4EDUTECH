<?php
// Mini-App Classroom Builder API - ENHANCED VERSION
// Handles AI conversation flow and HTML generation using OpenRouter

const APP_NAME = 'Mini-App Classroom Builder';
const APP_SLUG = 'mini-classroom-builder';
const ERROR_CODE_PREFIX = 'MCB';

header('Content-Type: application/json; charset=utf-8');

// CORS - Production domain only
$allowedOrigin = 'https://ai4edutech.com';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowedOrigin) {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Error logging
$errorLogPath = __DIR__ . '/' . APP_SLUG . '_errors.log';

function logError($code, $message, $context = []) {
    global $errorLogPath;
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE);
    $logEntry = "[$timestamp] [" . ERROR_CODE_PREFIX . "-$code] $message | Context: $contextStr\n";
    error_log($logEntry, 3, $errorLogPath);
}

function sendError($message, $code, $httpCode = 400) {
    logError($code, $message);
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Only POST requests are allowed.', '001', 405);
}

// Load API keys
$keysPath = dirname(__DIR__) . '/../.api_keys.php';
if (!file_exists($keysPath)) {
    sendError('API configuration missing.', '002', 500);
}

$keys = require $keysPath;
$openRouterApiKey = $keys['openRouterApiKey'] ?? null;

if (!$openRouterApiKey) {
    sendError('OpenRouter API key not configured.', '003', 500);
}

// Parse request body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON in request body.', '004');
}

$action = $body['action'] ?? 'chat';

// Handle save action
if ($action === 'save') {
    handleSaveHTML($body);
    exit;
}

// Handle HTML generation action
if ($action === 'generate') {
    handleHTMLGeneration($body, $openRouterApiKey);
    exit;
}

// Handle chat conversation
$userMessage = $body['message'] ?? '';
$conversationHistory = $body['conversationHistory'] ?? [];
$sessionState = $body['sessionState'] ?? [];
$language = $body['language'] ?? 'en';

if (empty($userMessage)) {
    sendError('Message field is required.', '005');
}

// Build system prompt based on the playbook
$systemPrompt = buildSystemPrompt($language, $sessionState);

// Prepare messages for OpenRouter
$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];

// Add conversation history (last 10 messages to manage token limits)
$recentHistory = array_slice($conversationHistory, -10);
foreach ($recentHistory as $msg) {
    $messages[] = [
        'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
        'content' => $msg['content']
    ];
}

// Add current user message
$messages[] = ['role' => 'user', 'content' => $userMessage];

// Call OpenRouter
try {
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    
    $payload = [
        'model' => 'anthropic/claude-3.5-sonnet',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2000
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $openRouterApiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://ai4edutech.com',
            'X-Title: ' . APP_NAME
        ],
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("cURL error: $curlError");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("OpenRouter returned HTTP $httpCode: $response");
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !isset($result['choices'][0]['message']['content'])) {
        throw new Exception('Invalid response from OpenRouter: ' . json_encode($result));
    }
    
    $assistantReply = $result['choices'][0]['message']['content'];
    
    // Detect state updates from the conversation
    $updatedState = detectStateUpdates($userMessage, $assistantReply, $sessionState);
    
    echo json_encode([
        'success' => true,
        'reply' => $assistantReply,
        'updatedState' => $updatedState
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logError('006', 'OpenRouter API call failed', ['error' => $e->getMessage()]);
    sendError('AI service temporarily unavailable. Please try again.', '006', 503);
}

function handleSaveHTML($body) {
    $html = $body['html'] ?? '';
    $slug = $body['slug'] ?? '';
    
    if (empty($html) || empty($slug)) {
        sendError('HTML and slug are required.', '008');
    }
    
    // Sanitize slug
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
    
    // Create directory
    $generatedDir = dirname(__DIR__) . '/apps/generated';
    if (!is_dir($generatedDir)) {
        mkdir($generatedDir, 0755, true);
    }
    
    $appDir = $generatedDir . '/' . $slug;
    if (!is_dir($appDir)) {
        mkdir($appDir, 0755, true);
    }
    
    // Save HTML file
    $htmlPath = $appDir . '/index.html';
    $result = file_put_contents($htmlPath, $html);
    
    if ($result === false) {
        sendError('Failed to save HTML file.', '009', 500);
    }
    
    $url = "https://ai4edutech.com/apps/generated/$slug/";
    
    echo json_encode([
        'success' => true,
        'url' => $url,
        'slug' => $slug
    ], JSON_UNESCAPED_UNICODE);
}

function handleHTMLGeneration($body, $apiKey) {
    $sessionState = $body['sessionState'] ?? [];
    $language = $body['language'] ?? 'en';
    
    // Build prompt for HTML generation
    $prompt = buildHTMLGenerationPrompt($sessionState, $language);
    
    try {
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        
        $payload = [
            'model' => 'anthropic/claude-3.5-sonnet',
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert HTML/CSS/JS developer specializing in educational mini-apps. CRITICAL RULES: (1) Generate EXACTLY the number of questions specified - count them before submitting. (2) ALWAYS include Part B (gap-fill) tab if gap count > 0. (3) NO WIZARD layouts - only direct quiz interfaces. (4) Working Print button (window.print). (5) Working Certificate button (Canvas API). Generate production-ready HTML matching the reference example quality.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.1,  // Very low for consistent question counts
            'max_tokens' => 16000
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: https://ai4edutech.com',
                'X-Title: ' . APP_NAME
            ],
            CURLOPT_TIMEOUT => 180
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("cURL error: $curlError");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("OpenRouter returned HTTP $httpCode: $response");
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !isset($result['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response from OpenRouter');
        }
        
        $generatedContent = $result['choices'][0]['message']['content'];
        
        // Extract HTML from markdown code blocks if present
        $html = extractHTML($generatedContent);
        
        // VALIDATION: Check if HTML contains expected question counts
        $mcqMatches = substr_count($html, 'name="mcq-');
        $gapMatches = substr_count($html, 'id="gap-');
        
        // Log validation results
        error_log("Generated HTML validation - Expected MCQs: {$sessionState['mcqCount']}, Found: {$mcqMatches}");
        error_log("Generated HTML validation - Expected Gaps: " . ($sessionState['gapCount'] ?? 0) . ", Found: {$gapMatches}");
        
        // If counts don't match, log warning but still return (let user decide)
        if ($mcqMatches < ($sessionState['mcqCount'] ?? 0)) {
            error_log("WARNING: Generated fewer MCQs than requested");
        }
        
        // Generate unique slug
        $slug = generateUniqueSlug($sessionState);
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'slug' => $slug
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        logError('007', 'HTML generation failed', ['error' => $e->getMessage()]);
        sendError('Failed to generate HTML. Please try again.', '007', 500);
    }
}

function buildHTMLGenerationPrompt($state, $language) {
    $theme = $state['selectedTheme'] ?? 'light';
    $contentType = $state['contentType'] ?? 'mcq';
    $content = $state['content'] ?? '';
    $teacherName = $state['teacherName'] ?? 'Teacher';
    $grade = $state['grade'] ?? 'G4';
    $cefr = $state['cefr'] ?? 'A2';
    $passMark = $state['passMark'] ?? 60;
    $mcqCount = $state['mcqCount'] ?? 12;
    $whatsappPhone = $state['whatsappPhone'] ?? '';
    $style = $state['style'] ?? 'simple';
    
    // Determine if we need gap-fill questions - ALWAYS include gaps for most content types
    $includeGaps = in_array($contentType, ['cloze', 'vocabulary', 'reading', 'mcq']);
    $gapCount = $includeGaps ? min($mcqCount, 10) : 0;
    
    // Clean WhatsApp phone for wa.me format
    $cleanPhone = preg_replace('/[^0-9]/', '', $whatsappPhone);
    
    $prompt = <<<PROMPT
Generate a complete HTML mini-app EXACTLY like the reference "Present Simple — Grade 6" example.

**CRITICAL: NO WIZARD LAYOUT - DIRECT QUIZ INTERFACE ONLY**

**ABSOLUTE REQUIREMENTS - COUNT VERIFICATION:**
1. You MUST generate EXACTLY $mcqCount MCQ questions in Part A
2. You MUST generate EXACTLY $gapCount Gap-fill questions in Part B
3. VERIFY: Count your questions after writing them - if not exact, rewrite
4. Part B is MANDATORY if $gapCount > 0
5. Both tabs MUST be present and functional

**YOUR SPECIFICATIONS:**
Theme: $theme
MCQ Questions (Part A): $mcqCount ← MUST BE EXACT
Gap-Fill Questions (Part B): $gapCount ← MUST BE EXACT
Teacher: $teacherName
Pass Mark: $passMark%
WhatsApp: $cleanPhone

**Source Content:**
$content

**VERIFICATION BEFORE OUTPUT:**
- Count MCQs: Should be exactly $mcqCount
- Count Gaps: Should be exactly $gapCount
- Check tabs: Both Part A and Part B tabs exist
- Check buttons: Start, Print, Certificate, Share all present

**EXACT STRUCTURE - COPY THIS PATTERN:**

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>[Auto-generate from content] — Grade X</title>
<style>
  :root{
    --bg1:#b3e5fc; --bg2:#a7ffeb; --card:#ffffff; --ink:#0b2b3a;
    --accent:#0ea5e9; --accent2:#10b981; --radius:20px;
  }
  /* Copy ALL CSS from reference */
</style>
</head>
<body>
  <div class="wrap">
    <header>
      <div class="brand">
        <h1>[Title] — Grade X</h1>
        <small>Teacher: <strong>$teacherName</strong> • Theme: [theme] • Style: $style</small>
      </div>
      <div class="tags">
        <span class="tag">MCQs: $mcqCount</span>
        <span class="tag">Gaps: $gapCount</span>
        <span class="tag">Pass: $passMark%</span>
        <span class="tag">Certificate</span>
        <span class="tag">Print</span>
        <span class="tag">WhatsApp</span>
      </div>
    </header>

    <!-- START CARD -->
    <section id="startCard" class="card">
      <h2>Start</h2>
      <div class="start-grid">
        <p class="footer-note">Enter your name and click <strong>Start Activity</strong>.</p>
        <div class="row">
          <div>
            <label for="studentName">Student Name</label>
            <input id="studentName" type="text" placeholder="Type your name…" />
          </div>
          <div>
            <label for="teacherName">Teacher (optional)</label>
            <input id="teacherName" type="text" value="$teacherName" />
          </div>
        </div>
        <div class="toolbar">
          <button class="btn ok" id="btnStart">Start Activity</button>
          <button class="btn ghost" id="btnSkipName">Skip Name</button>
          <button class="btn" id="btnPrint">Print</button>
        </div>
      </div>
    </section>

    <!-- TABS -->
    <nav class="tabs">
      <button class="tab" role="tab" aria-selected="true" id="mcqTab">Part A: MCQs</button>
      <button class="tab" role="tab" aria-selected="false" id="gapTab">Part B: Fill in the Gaps</button>
      <span class="pill">Progress: <strong id="progText">0/$mcqCount</strong></span>
    </nav>

    <!-- MCQ PANEL -->
    <section id="mcqPanel" class="card">
      <h2>Part A — Multiple Choice</h2>
      <div id="mcqList"></div>
      <div class="toolbar">
        <button class="btn" id="checkMcq">Check MCQs</button>
        <button class="btn ghost" id="resetMcq">Reset MCQs</button>
      </div>
    </section>

    <!-- GAP PANEL -->
    <section id="gapPanel" class="card" hidden>
      <h2>Part B — Fill in the Gaps</h2>
      <div id="gapList"></div>
      <div class="toolbar">
        <button class="btn" id="checkGaps">Check Gaps</button>
        <button class="btn ghost" id="resetGaps">Reset Gaps</button>
      </div>
    </section>

    <!-- RESULTS -->
    <section class="card">
      <h2>Results</h2>
      <div class="result">
        <div>
          <strong>Score:</strong> <span id="scoreXY">0/$mcqCount</span> (<span id="scorePct">0%</span>)
          <span class="badge fail" id="passBadge">Not Passed</span>
        </div>
        <div class="toolbar">
          <button class="btn ok" id="btnCertificate" disabled>Certificate</button>
          <button class="btn" id="btnShare">Share via WhatsApp</button>
          <button class="btn" id="btnPrint2">Print</button>
        </div>
      </div>
      <div class="progress">
        <div id="progressBar" style="width:0%"></div>
      </div>
    </section>
  </div>

  <!-- HIDDEN CANVAS -->
  <canvas id="certCanvas" width="1200" height="850" style="display:none"></canvas>

  <script>
  (() => {
    const MCQS = [/* Generate $mcqCount questions from content */];
    const GAPS = [/* Generate $gapCount questions if applicable */];

    const state = {
      name: "",
      teacher: "$teacherName",
      passMark: $passMark,
      mcqScore: 0,
      gapScore: 0
    };

    // DOM elements
    const \$ = sel => document.querySelector(sel);

    // Render questions
    function renderMcqs(){ /* ... */ }
    function renderGaps(){ /* ... */ }

    // Check answers
    function checkMcqs(){ /* ... */ }
    function checkGaps(){ /* ... */ }

    // Update score
    function updateScore(){
      const total = MCQS.length + GAPS.length;
      const got = state.mcqScore + state.gapScore;
      const pct = Math.round((got/total)*100);
      \$("#scoreXY").textContent = \`\${got}/\${total}\`;
      \$("#scorePct").textContent = \`\${pct}%\`;
      const pass = pct >= state.passMark;
      \$("#passBadge").textContent = pass ? "Passed" : "Not Passed";
      \$("#passBadge").className = \`badge \${pass ? 'pass' : 'fail'}\`;
      \$("#btnCertificate").disabled = !pass;
      \$("#progressBar").style.width = \`\${(got/total)*100}%\`;
    }

    // Certificate - WORKING VERSION
    function makeCertificatePNG(){
      const c = document.getElementById("certCanvas");
      const ctx = c.getContext("2d");
      
      // Set size
      c.width = 1200;
      c.height = 850;
      
      // White background
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, c.width, c.height);
      
      // Gold border
      ctx.strokeStyle = "#d4af37";
      ctx.lineWidth = 20;
      ctx.strokeRect(10, 10, c.width-20, c.height-20);
      ctx.lineWidth = 2;
      ctx.strokeRect(30, 30, c.width-60, c.height-60);
      
      // Title
      ctx.fillStyle = "#1e293b";
      ctx.font = "bold 60px Arial";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText("Certificate of Achievement", c.width/2, 130);
      
      // "This certifies that"
      ctx.fillStyle = "#475569";
      ctx.font = "28px Arial";
      ctx.fillText("This certifies that", c.width/2, 210);
      
      // Student name
      ctx.fillStyle = "#0ea5e9";
      ctx.font = "bold 72px Arial";
      ctx.fillText(state.name || "Student", c.width/2, 290);
      
      // "has successfully completed"
      ctx.fillStyle = "#475569";
      ctx.font = "26px Arial";
      ctx.fillText("has successfully completed", c.width/2, 355);
      
      // Quiz title
      ctx.fillStyle = "#1e293b";
      ctx.font = "bold 38px Arial";
      ctx.fillText("[Quiz Title]", c.width/2, 395);
      
      // Score bar
      const total = MCQS.length + GAPS.length;
      const got = state.mcqScore + state.gapScore;
      const pct = Math.round((got/total)*100);
      const barX=240, barY=465, barW=720, barH=30;
      
      ctx.fillStyle = "#e0f2fe";
      ctx.fillRect(barX, barY, barW, barH);
      ctx.fillStyle = "#10b981";
      ctx.fillRect(barX, barY, barW*(pct/100), barH);
      ctx.strokeStyle = "#0ea5e9";
      ctx.lineWidth = 2;
      ctx.strokeRect(barX, barY, barW, barH);
      
      ctx.fillStyle = "#1e293b";
      ctx.font = "26px Arial";
      ctx.fillText(\`Score: \${got}/\${total} (\${pct}%)\`, c.width/2, barY+70);
      
      // Footer
      ctx.textAlign = "left";
      ctx.fillStyle = "#475569";
      ctx.font = "24px Arial";
      ctx.fillText("Teacher: $teacherName", 120, 720);
      ctx.fillText("Date: " + new Date().toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}), 120, 760);
      ctx.textAlign = "right";
      ctx.fillText("Signature: ____________________", c.width-120, 752);
      
      // Download
      const link = document.createElement("a");
      link.download = "Certificate_" + (state.name || "Student").replace(/\s+/g, "_") + ".png";
      link.href = c.toDataURL("image/png");
      link.click();
    }

    // WhatsApp share
    function shareWhatsApp(){
      const total = MCQS.length + GAPS.length;
      const got = state.mcqScore + state.gapScore;
      const pct = Math.round((got/total)*100);
      const msg = encodeURIComponent(\`Hello, I'm \${state.name || "Student"}. My score is \${got}/\${total} (\${pct}%).\`);
      window.open(\`https://wa.me/$cleanPhone?text=\${msg}\`, "_blank");
    }

    // Event listeners
    \$("#btnStart").addEventListener("click", ()=>{
      state.name = \$("#studentName").value.trim();
      \$("#startCard").style.display="none";
    });
    \$("#btnSkipName").addEventListener("click", ()=>{\$("#startCard").style.display="none"});
    \$("#btnPrint").addEventListener("click", ()=>window.print());
    \$("#btnPrint2").addEventListener("click", ()=>window.print());
    \$("#btnCertificate").addEventListener("click", makeCertificatePNG);
    \$("#btnShare").addEventListener("click", shareWhatsApp);
    \$("#checkMcq").addEventListener("click", checkMcqs);
    \$("#checkGaps").addEventListener("click", checkGaps);

    // Initialize
    renderMcqs();
    renderGaps();
    updateScore();
  })();
  </script>
</body>
</html>
```

**CRITICAL REQUIREMENTS:**

1. **NO WIZARD** - Direct quiz interface, not conversation flow
2. **WORKING PRINT** - \`window.print()\` on both Print buttons
3. **WORKING CERTIFICATE** - Full Canvas code with proper PNG download
4. **Exact $mcqCount MCQs** - Generate from content
5. **Exact $gapCount gaps** - If applicable
6. **Real questions** - Not placeholders
7. **Theme colors** - Match selected theme
8. **All buttons work** - Start, Skip, Print, Certificate, Share, Check, Reset

**MANDATORY GAP GENERATION:**
If $gapCount > 0, you MUST:
1. Create $gapCount gap-fill questions in the GAPS array
2. Implement renderGaps() function that displays them
3. Implement checkGaps() function that validates answers
4. Make sure the gap tab is visible and functional
5. Include gap questions in the total score calculation

**EXAMPLE GAP QUESTIONS FORMAT:**
```javascript
const GAPS = [
  {
    id: "gap-1",
    text: "The cat is sitting ___ the table.",
    answer: "on",
    options: ["on", "in", "at", "by"]
  },
  {
    id: "gap-2", 
    text: "She ___ to school every day.",
    answer: "goes",
    options: ["go", "goes", "going", "went"]
  }
  // ... continue for $gapCount total
];
```

Output ONLY complete HTML. NO markdown. NO explanations.
PROMPT;

    return $prompt;
}

function extractHTML($content) {
    // Remove markdown code blocks if present
    $pattern = '/```html\s*(.*?)\s*```/is';
    if (preg_match($pattern, $content, $matches)) {
        return trim($matches[1]);
    }
    
    $pattern = '/```\s*(.*?)\s*```/is';
    if (preg_match($pattern, $content, $matches)) {
        return trim($matches[1]);
    }
    
    // If already HTML, return as is
    if (stripos($content, '<!DOCTYPE') !== false) {
        return trim($content);
    }
    
    // Fallback: return content as is
    return trim($content);
}

function generateUniqueSlug($state) {
    $contentType = $state['contentType'] ?? 'app';
    $timestamp = time();
    $random = substr(md5(uniqid()), 0, 6);
    return $contentType . '-' . $timestamp . '-' . $random;
}

function buildSystemPrompt($language, $sessionState) {
    $lang = $language === 'ar' ? 'Arabic' : 'English';
    $selectedTheme = $sessionState['selectedTheme'] ?? 'not selected yet';
    $contentType = $sessionState['contentType'] ?? 'not detected yet';
    $teacherName = $sessionState['teacherName'] ?? 'not provided';
    $grade = $sessionState['grade'] ?? 'not set';
    $cefr = $sessionState['cefr'] ?? 'not set';
    $passMark = $sessionState['passMark'] ?? 60;
    $mcqCount = $sessionState['mcqCount'] ?? 'not set';
    $whatsappPhone = $sessionState['whatsappPhone'] ?? 'not provided';
    $style = $sessionState['style'] ?? 'simple';
    
    $prompt = <<<PROMPT
You are the Mini-App Classroom Builder assistant. Help teachers create direct quiz/test interfaces - NO WIZARD LAYOUTS.

**Core Rules:**
1. **One question at a time.** Keep responses short and focused.
2. **Detect content type** automatically when the teacher pastes text.
3. **Use the selected theme:** {$selectedTheme}
4. **Conversation language:** {$lang}
5. **Direct, efficient tone.**

**Conversation Flow:**
- Theme selected: {$selectedTheme}
- Ask for: content type, teacher name, grade, pass mark, MCQ count, WhatsApp phone
- Each question should be ONE thing only
- Before building, summarize settings and ask: "Ready to build?"

**Current State:**
- Theme: {$selectedTheme}
- Content Type: {$contentType}
- Teacher: {$teacherName}
- Grade/CEFR: {$grade}/{$cefr}
- Pass Mark: {$passMark}%
- MCQ Count: {$mcqCount}
- WhatsApp: {$whatsappPhone}
- Style: {$style}

**Content Detection:**
- Vocabulary: list format
- Reading: paragraphs
- MCQ: questions with options
- Cloze: text with gaps
- Matching: term-definition pairs

Respond in {$lang}. Be concise (1-2 sentences).
PROMPT;

    return $prompt;
}

function detectStateUpdates($userMessage, $assistantReply, $currentState) {
    $updates = [];
    
    // Detect content intent (game vs application)
    if (stripos($userMessage, 'game') !== false || stripos($userMessage, 'لعبة') !== false) {
        $updates['contentIntent'] = 'game';
    } elseif (stripos($userMessage, 'application') !== false || stripos($userMessage, 'app') !== false || stripos($userMessage, 'تطبيق') !== false) {
        $updates['contentIntent'] = 'application';
    }
    
    // Detect content type from keywords
    $contentKeywords = [
        'vocab' => 'vocabulary',
        'vocabulary' => 'vocabulary',
        'words' => 'vocabulary',
        'reading' => 'reading',
        'passage' => 'reading',
        'comprehension' => 'reading',
        'mcq' => 'mcq',
        'quiz' => 'mcq',
        'questions' => 'mcq',
        'cloze' => 'cloze',
        'gap' => 'cloze',
        'fill' => 'cloze',
        'matching' => 'matching',
        'match' => 'matching',
        'pairs' => 'matching',
        'listening' => 'listening',
        'dictation' => 'listening'
    ];
    
    foreach ($contentKeywords as $keyword => $type) {
        if (stripos($userMessage, $keyword) !== false) {
            $updates['contentType'] = $type;
            break;
        }
    }
    
    // Detect if user pasted large content (likely lesson material)
    if (strlen($userMessage) > 200 && !isset($updates['contentType'])) {
        // Auto-detect based on content structure
        if (substr_count($userMessage, '?') >= 3 && (stripos($userMessage, 'A)') !== false || stripos($userMessage, 'a.') !== false)) {
            $updates['contentType'] = 'mcq';
        } elseif (substr_count($userMessage, '___') >= 3 || substr_count($userMessage, '_____') >= 3) {
            $updates['contentType'] = 'cloze';
        } elseif (substr_count($userMessage, '—') >= 3 || substr_count($userMessage, ' - ') >= 3) {
            $updates['contentType'] = 'matching';
        } elseif (substr_count($userMessage, "\n") >= 5 && preg_match('/[.!?]\s+[A-Z]/', $userMessage)) {
            $updates['contentType'] = 'reading';
        } elseif (substr_count($userMessage, "\n") >= 3 && strlen($userMessage) < 500) {
            $updates['contentType'] = 'vocabulary';
        }
    }
    
    // Detect teacher name
    if (preg_match('/teacher[:\s]+([^\n,]+)/i', $userMessage, $matches)) {
        $updates['teacherName'] = trim($matches[1]);
    } elseif (preg_match('/(?:my name is|I am|I\'m)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i', $userMessage, $matches)) {
        $updates['teacherName'] = trim($matches[1]);
    }
    
    // Detect grade
    if (preg_match('/grade[:\s]+(\d+|G\d+)/i', $userMessage, $matches)) {
        $updates['grade'] = strtoupper($matches[1]);
    } elseif (preg_match('/\b(G\d+|grade\s*\d+)\b/i', $userMessage, $matches)) {
        $updates['grade'] = strtoupper($matches[1]);
    }
    
    // Detect CEFR level
    if (preg_match('/\b([ABC][12])\b/i', $userMessage, $matches)) {
        $updates['cefr'] = strtoupper($matches[1]);
    }
    
    // Detect pass mark
    if (preg_match('/pass[:\s]+(\d+)%?/i', $userMessage, $matches)) {
        $updates['passMark'] = (int)$matches[1];
    } elseif (preg_match('/(\d{2})%/', $userMessage, $matches)) {
        $percentage = (int)$matches[1];
        if ($percentage >= 40 && $percentage <= 100) {
            $updates['passMark'] = $percentage;
        }
    }
    
    // Detect MCQ count
    if (preg_match('/(\d+)\s*(questions?|mcqs?|items?)/i', $userMessage, $matches)) {
        $count = (int)$matches[1];
        if ($count >= 1 && $count <= 50) {
            $updates['mcqCount'] = $count;
        }
    }
    
    // Detect WhatsApp phone (with country code)
    if (preg_match('/(\+\d{10,15})/', $userMessage, $matches)) {
        $updates['whatsappPhone'] = $matches[1];
    } elseif (preg_match('/(\d{10,15})/', $userMessage, $matches)) {
        // Phone without +, store as-is
        $updates['whatsappPhone'] = $matches[1];
    }
    
    // Detect style preference
    if (stripos($userMessage, 'playful') !== false || stripos($userMessage, 'fun') !== false || stripos($userMessage, 'colorful') !== false) {
        $updates['style'] = 'playful';
    } elseif (stripos($userMessage, 'simple') !== false || stripos($userMessage, 'clean') !== false || stripos($userMessage, 'minimal') !== false) {
        $updates['style'] = 'simple';
    }
    
    return $updates;
}
?>