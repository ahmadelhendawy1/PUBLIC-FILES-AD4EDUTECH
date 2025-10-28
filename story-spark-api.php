<?php
/**
 * Story Spark ESL — Backend API
 * - Fixes 500 errors
 * - Reads keys from one level above public_html
 * - Calls Replicate (FLUX → SDXL) + Google Custom Search (Images)
 * - Uses OpenRouter to generate story JSON (pages/captions/questions/imagePrompt)
 * - Integrated with credit system
 */

define('APP_NAME','Story Spark ESL');
define('APP_SLUG','story-spark');
define('ERROR_CODE_PREFIX','SSP_ERR_');

$errorLogFile = __DIR__ . '/' . APP_SLUG . '_errors.log';
ini_set('log_errors',1); ini_set('error_log',$errorLogFile);
ini_set('display_errors',0); error_reporting(E_ALL);
ini_set('memory_limit','512M'); set_time_limit(180);

$apiKeysPath = dirname(__DIR__) . '/../.api_keys.php'; // one level above public_html
if (!file_exists($apiKeysPath)) {
  error_log('Keys file not found at: ' . $apiKeysPath);
}
$apiKeys = file_exists($apiKeysPath) ? require $apiKeysPath : [];

// Credit system
define('CONSUME_CREDITS_API', dirname(__FILE__) . '/consume_credits_api.php');
define('APP_COST', 15); // Set cost per story generation

function consumeCredits($amount, $appName) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    $postData = ['credits_to_consume' => $amount, 'app_name' => $appName];
    $_POST = $postData;
    
    ob_start();
    include(CONSUME_CREDITS_API);
    $output = ob_get_clean();
    
    return json_decode($output, true);
}

$openRouterKey = $apiKeys['openRouterApiKey'] ?? '';
$replicateKey  = $apiKeys['replicateApiKey']  ?? '';  // add this key in .api_keys.php if missing
$googleKey     = $apiKeys['googleCseApiKey']  ?? '';
$googleCx      = $apiKeys['googleCseCx']      ?? '';
$openAiKey     = $apiKeys['openAIApiKey']     ?? '';  // optional (not used here)

header('Content-Type: application/json');
// In production prefer to lock CORS:
header('Access-Control-Allow-Origin: https://ai4edutech.com');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){ http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST') sendErr('Invalid request method.',405,'METHOD_NOT_ALLOWED');

$input = file_get_contents('php://input');
if (!$input) sendErr('No input body.',400,'NO_INPUT');
$data = json_decode($input, true);
if (!is_array($data)) sendErr('Invalid JSON.',400,'BAD_JSON');

$action = trim($data['action'] ?? '');
if ($action==='') sendErr('Missing action.',400,'NO_ACTION');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'ai4edutech.com';
$httpReferer = $scheme . '://' . $host . '/apps/' . APP_SLUG . '/';

try {

  if ($action === 'generate_pdf') {
    handleEnhancedPDF($data);
    exit; // headers/content set inside
  }

  if ($action === 'getCredits') {
    // Return current credit balance without consuming credits
    $creditResult = consumeCredits(0, APP_NAME);
    if ($creditResult['success']) {
      echo json_encode([
        'success' => true,
        'new_balance' => $creditResult['new_balance'] ?? 0
      ], JSON_UNESCAPED_SLASHES);
    } else {
      echo json_encode([
        'success' => false,
        'error' => $creditResult['error'] ?? 'Unable to get credit balance'
      ], JSON_UNESCAPED_SLASHES);
    }
    exit;
  }

  if ($action !== 'build_storybook') {
    sendErr('Unknown action.',400,'BAD_ACTION');
  }

  // Credit check before expensive story generation
  $creditResult = consumeCredits(APP_COST, APP_NAME);
  if (!$creditResult['success']) {
    http_response_code(402);
    sendErr($creditResult['error'], 402, 'INSUFFICIENT_CREDITS');
  }

  $topic = trim($data['topic'] ?? 'family');
  $pages = max(2, min(6, intval($data['pages'] ?? 4)));
  $level = strtoupper(trim($data['level'] ?? 'A2'));
  $imageMode = strtolower(trim($data['image_mode'] ?? 'auto'));

  error_log("Story Spark request: topic={$topic}, pages={$pages}, level={$level}, imgMode={$imageMode}");

  $storyPages = [];
  $source = 'handcrafted_premium';
  $imageCount = 0;

  // 1) Try OpenRouter to generate a proper JSON story (REAL story; per-page question + imagePrompt)
  $storyData = null;
  if (!empty($openRouterKey)) {
    $prompt = "Create a captivating children's story about '$topic' with exactly $pages pages for $level ESL students.

CRITICAL REQUIREMENTS:
- Real story with characters and plot progression (no generic blurbs)
- Vocabulary must fit $level ESL
- Each page contains: caption (one or two sentences), a comprehension question with 4 options, and 'answer' index
- Provide 'imagePrompt' describing the scene for a child-friendly, text-free illustration

Return ONLY strict JSON:
{
  \"pages\": [
    {
      \"caption\": \"...\",
      \"question\": { \"q\": \"...\", \"options\": [\"a\",\"b\",\"c\",\"d\"], \"answer\": 0 },
      \"imagePrompt\": \"...\"
    }
  ]
}";

    $payload = [
      'model' => 'openai/gpt-4o-mini',
      'messages' => [
        ['role'=>'system','content'=>"You are a professional children's story writer for ESL learners. Always return valid JSON in the requested schema."],
        ['role'=>'user','content'=>$prompt]
      ],
      'temperature' => 0.8,
      'max_tokens' => 3200,
      'response_format' => ['type'=>'json_object']
    ];

    $or = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($or, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $openRouterKey,
        'Content-Type: application/json',
        'HTTP-Referer: ' . $httpReferer,
        'X-Title: AI4EduTech ' . APP_NAME
      ],
      CURLOPT_POSTFIELDS => json_encode($payload),
      CURLOPT_CONNECTTIMEOUT => 30,
      CURLOPT_TIMEOUT => 120,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($or);
    $code = curl_getinfo($or, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($or);
    curl_close($or);

    if ($code===200 && $resp && !$curlErr) {
      $decoded = json_decode($resp, true);
      $content = $decoded['choices'][0]['message']['content'] ?? '';
      $content = cleanPossibleFences($content);
      $storyData = json_decode($content, true);
      if (json_last_error() !== JSON_ERROR_NONE || empty($storyData['pages'])) {
        error_log('OpenRouter JSON parse failed: ' . json_last_error_msg());
        $storyData = null;
      } else {
        $source = 'openrouter_enhanced';
      }
    } else {
      error_log("OpenRouter failed: HTTP {$code}. Curl: {$curlErr}");
    }
  } else {
    error_log('OpenRouter key missing; using fallback content.');
  }

  // 2) Build pages from LLM or fallback templates
  if (is_array($storyData) && !empty($storyData['pages'])) {
    for ($i=0; $i<$pages; $i++){
      $p = $storyData['pages'][$i] ?? $storyData['pages'][0];
      $caption = trim($p['caption'] ?? "Page ".($i+1)." about $topic.");
      $question = $p['question'] ?? [
        'q' => 'What happens in this part of the story?',
        'options' => ['Something exciting','Nothing happens','Something boring','Something confusing'],
        'answer' => 0
      ];
      $imagePrompt = trim($p['imagePrompt'] ?? "Children's storybook illustration about $topic page ".($i+1));
      $imgUrl = '';
      if ($imageMode !== 'none') {
        $proPrompt = "Professional children's storybook illustration: {$imagePrompt}. High-quality, vibrant colors, friendly characters, beautiful composition, no text.";
        $imgUrl = generatePremiumImage($proPrompt, $imageMode, $replicateKey, $googleKey, $googleCx, $i);
        if (!empty($imgUrl)) $imageCount++;
      }
      $storyPages[] = [
        'id' => 'page_'.($i+1),
        'caption' => $caption,
        'question' => normalizeQuestion($question),
        'img' => $imgUrl,
        'imagePrompt' => $imagePrompt
      ];
    }
  } else {
    // Fallback curated templates (shortened)
    $templates = getPremiumTemplates($topic);
    for ($i=0; $i<$pages; $i++){
      $tp = $templates[$i % count($templates)];
      $proPrompt = "Professional children's storybook illustration: {$tp['prompt']}. High-quality digital art, vibrant colors, friendly characters, no text.";
      $imgUrl = '';
      if ($imageMode !== 'none') {
        $imgUrl = generatePremiumImage($proPrompt, $imageMode, $replicateKey, $googleKey, $googleCx, $i);
        if (!empty($imgUrl)) $imageCount++;
      }
      $storyPages[] = [
        'id' => 'page_'.($i+1),
        'caption' => $tp['caption'],
        'question' => normalizeQuestion($tp['question']),
        'img' => $imgUrl,
        'imagePrompt' => $tp['prompt']
      ];
    }
  }

  $out = [
    'success' => true,
    'data' => ['pages' => $storyPages],
    'new_balance' => $creditResult['new_balance'] ?? 0, // Include new balance
    'meta' => [
      'topic' => $topic,
      'level' => $level,
      'pages' => count($storyPages),
      'image_mode' => $imageMode,
      'images_generated' => $imageCount,
      'story_source' => $source,
      'credits_used' => APP_COST,
      'timestamp' => date('Y-m-d H:i:s')
    ]
  ];
  echo json_encode($out, JSON_UNESCAPED_SLASHES);
  exit;

} catch(Throwable $e){
  error_log('FATAL: '.$e->getMessage());
  sendErr('Unexpected server error.',500,'EXCEPTION',$e->getMessage());
}

/* ---------- Helpers ---------- */

function sendErr($msg,$code,$suffix,$log=''){
  http_response_code($code);
  if ($log) error_log($log);
  echo json_encode(['success'=>false,'error'=>$msg,'errorCode'=>ERROR_CODE_PREFIX.$suffix], JSON_UNESCAPED_SLASHES);
  exit;
}

function cleanPossibleFences($txt){
  // Remove ```json ... ``` fences if present
  $txt = trim($txt);
  if (preg_match('/^```/',$txt)) {
    $txt = preg_replace('/^```(?:json)?\s*/i','',$txt);
    $txt = preg_replace('/\s*```$/','',$txt);
  }
  return trim($txt);
}

function normalizeQuestion($q){
  $qText = $q['q'] ?? 'Question';
  $opts = $q['options'] ?? ['A','B','C','D'];
  if (!is_array($opts)) $opts = ['A','B','C','D'];
  // force 4 options
  while (count($opts)<4) $opts[] = '—';
  $opts = array_slice($opts,0,4);
  $ans = intval($q['answer'] ?? 0);
  if ($ans<0 || $ans>3) $ans = 0;
  return ['q'=>$qText,'options'=>$opts,'answer'=>$ans];
}

function getPremiumTemplates($topic){
  $t = strtolower($topic);
  $map = [
    'family' => [
      [ 'caption'=>'The Martinez family packs a picnic for Riverside Park.', 'prompt'=>'Happy family packing picnic basket, kids helping, sunny day', 'question'=>['q'=>'What is the family preparing?','options'=>['A picnic','A school trip','A movie night','A birthday cake'],'answer'=>0] ],
      [ 'caption'=>'Mom wraps sandwiches; Dad fills the cooler with juice and fruit.', 'prompt'=>'Parents preparing food and drinks in cozy kitchen', 'question'=>['q'=>'What does Dad put in the cooler?','options'=>['Juice and fruit','Books','Shoes','Plates'],'answer'=>0] ],
      [ 'caption'=>'The kids carry blankets and games under a big oak tree.', 'prompt'=>'Children carrying blankets to a shady park tree', 'question'=>['q'=>'Where do they set up the picnic?','options'=>['Under a big tree','On the street','In the car','At school'],'answer'=>0] ],
      [ 'caption'=>'They eat, play, and watch the orange-pink sunset together.', 'prompt'=>'Family playing frisbee, sunset sky in orange and pink', 'question'=>['q'=>'What colors are in the sunset?','options'=>['Orange and pink','Black and white','Blue only','Green and purple'],'answer'=>0] ],
    ],
    'farm animals' => [
      [ 'caption'=>'Farmer Sarah walks to the red barn in the morning mist.', 'prompt'=>'Young farmer walking to big red barn at dawn', 'question'=>['q'=>'Where is Sarah going?','options'=>['To the barn','To the shop','To school','To the city'],'answer'=>0] ],
      [ 'caption'=>'White chickens run to Sarah for breakfast.', 'prompt'=>'Happy white chickens running in farmyard', 'question'=>['q'=>'Why are chickens excited?','options'=>['It is feeding time','They are tired','It is raining','It is night'],'answer'=>0] ],
      [ 'caption'=>'Cow Bessie moos softly while Sarah milks her.', 'prompt'=>'Farmer milking friendly brown cow in barn', 'question'=>['q'=>'What does Sarah get from Bessie?','options'=>['Milk','Eggs','Wool','Honey'],'answer'=>0] ],
      [ 'caption'=>'Animals play together in the sunny meadow.', 'prompt'=>'Farm animals playing together in green field', 'question'=>['q'=>'When do they play?','options'=>['After eating','Before sunrise','During storm','Never'],'answer'=>0] ],
    ],
    'space adventure' => [
      [ 'caption'=>'Captain Luna checks her silver spaceship before liftoff.', 'prompt'=>'Female astronaut in cockpit checking controls, view of Mars', 'question'=>['q'=>'What does Luna check?','options'=>['Buttons and controls','Her shoes','A pizza','A book'],'answer'=>0] ],
      [ 'caption'=>'On Mars, Luna meets friendly purple aliens.', 'prompt'=>'Cute purple aliens waving on red Martian ground', 'question'=>['q'=>'What color are the aliens?','options'=>['Purple','Green','Blue','Yellow'],'answer'=>0] ],
      [ 'caption'=>'Aliens show Luna a crystal city with tall towers.', 'prompt'=>'Sparkling crystal city with diamond-like towers', 'question'=>['q'=>'What is the city like?','options'=>['Crystal towers','Dark caves','Wood houses','Ice huts'],'answer'=>0] ],
      [ 'caption'=>'Luna takes photos and promises to visit again.', 'prompt'=>'Astronaut taking photos with friendly aliens', 'question'=>['q'=>'What does Luna promise?','options'=>['To return','To hide','To fight','To stay forever'],'answer'=>0] ],
    ],
  ];
  return $map[$t] ?? $map['family'];
}

/* ---------- Image generation pipeline ---------- */

function generatePremiumImage($prompt, $mode, $replicateKey, $googleKey, $googleCx, $pageIndex){
  if ($mode === 'none') return '';
  // 1) Replicate (FLUX → SDXL)
  if (($mode==='replicate' || $mode==='auto') && !empty($replicateKey)) {
    $img = generateFluxReplicate($replicateKey, $prompt);
    if ($img) return $img;
    $img = generateSDXLReplicate($replicateKey, $prompt);
    if ($img) return $img;
  }
  // 2) Google Images
  if (($mode==='google' || $mode==='auto') && !empty($googleKey) && !empty($googleCx)) {
    $img = generateGooglePhotosSearch($googleKey, $googleCx, $prompt, $pageIndex);
    if ($img) return $img;
  }
  return '';
}

/** Replicate: FLUX (default model endpoint path -> uses default latest version) */
function generateFluxReplicate($apiKey, $prompt){
  try{
    $payload = json_encode([
      'input' => [
        'prompt' => $prompt,
        'num_outputs' => 1,
        'aspect_ratio' => '1:1',
        'output_format' => 'png',
        'output_quality' => 95,
        'seed' => rand(1000,99999)
      ]
    ]);
    $ch = curl_init('https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Token ' . $apiKey,
        'Content-Type: application/json',
        'User-Agent: StorySpark/1.0'
      ],
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_CONNECTTIMEOUT => 20,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_SSL_VERIFYPEER => true
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code===201 && $res && !$err) {
      $decoded = json_decode($res,true);
      if (!empty($decoded['id'])) return pollReplicateResult($apiKey, $decoded['id'], 'FLUX');
    } else {
      error_log("FLUX create failed: HTTP {$code} / {$err}");
    }
  }catch(Throwable $e){ error_log('FLUX error: '.$e->getMessage()); }
  return '';
}

/** Replicate: SDXL fallback */
function generateSDXLReplicate($apiKey, $prompt){
  try{
    $payload = json_encode([
      'input' => [
        'prompt' => $prompt,
        'width' => 1024,
        'height' => 1024,
        'num_outputs' => 1,
        'num_inference_steps' => 40,
        'guidance_scale' => 8.0,
        'negative_prompt' => 'ugly, blurry, distorted, bad anatomy, watermark, text, words, letters, lowres, low quality, artifacts'
      ]
    ]);
    $ch = curl_init('https://api.replicate.com/v1/models/stability-ai/sdxl/predictions');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Token ' . $apiKey,
        'Content-Type: application/json'
      ],
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_CONNECTTIMEOUT => 20,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_SSL_VERIFYPEER => true
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code===201 && $res && !$err) {
      $decoded = json_decode($res,true);
      if (!empty($decoded['id'])) return pollReplicateResult($apiKey, $decoded['id'], 'SDXL');
    } else {
      error_log("SDXL create failed: HTTP {$code} / {$err}");
    }
  }catch(Throwable $e){ error_log('SDXL error: '.$e->getMessage()); }
  return '';
}

function pollReplicateResult($apiKey, $predictionId, $modelLabel='Replicate'){
  $maxAttempts = 30;
  for ($i=0; $i<$maxAttempts; $i++){
    sleep(3);
    $ch = curl_init("https://api.replicate.com/v1/predictions/{$predictionId}");
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Authorization: Token ' . $apiKey],
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_SSL_VERIFYPEER => true
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code===200 && $res && !$err){
      $decoded = json_decode($res, true);
      $status = $decoded['status'] ?? 'unknown';
      if ($status==='succeeded'){
        $out = $decoded['output'] ?? null;
        if (is_array($out) && !empty($out[0])) return $out[0];
        if (is_string($out) && filter_var($out, FILTER_VALIDATE_URL)) return $out;
        return '';
      }
      if ($status==='failed'){
        error_log("$modelLabel failed: " . ($decoded['error'] ?? 'unknown'));
        return '';
      }
    }
  }
  error_log("$modelLabel polling timeout for {$predictionId}");
  return '';
}

/** Google Custom Search (Images) */
function generateGooglePhotosSearch($apiKey, $cx, $prompt, $pageIndex){
  try{
    // soften/clean prompt for search terms
    $searchTerms = trim(preg_replace('/\s+/', ' ', preg_replace('/(Professional|High-quality|illustration:)/i','',$prompt)));
    $searchTerms .= ' children storybook illustration cartoon educational colorful';
    $params = [
      'key' => $apiKey,
      'cx'  => $cx,
      'q'   => $searchTerms,
      'searchType' => 'image',
      'num' => 3,
      'start' => max(1, 1 + ($pageIndex * 5)),
      'safe' => 'active',
      'imgSize' => 'xlarge',
      'imgType' => 'clipart',
      'imgColorType' => 'color'
    ];
    $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 20,
      CURLOPT_TIMEOUT => 25,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_HTTPHEADER => [
        'User-Agent: StorySpark/1.0',
        'Accept: application/json'
      ]
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code===200 && $res && !$err){
      $json = json_decode($res, true);
      if (!empty($json['items'])) {
        foreach ($json['items'] as $item){
          $imageUrl = $item['link'] ?? '';
          if ($imageUrl && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            if (isUrlOk($imageUrl)) {
              $w = intval($item['image']['width'] ?? 0);
              $h = intval($item['image']['height'] ?? 0);
              if ($w>=300 && $h>=300) return $imageUrl;
            }
          }
        }
      }
    } else {
      error_log("Google Images failed: HTTP {$code}/{$err}");
    }
  }catch(Throwable $e){ error_log('Google Images error: '.$e->getMessage()); }
  return '';
}

function isUrlOk($url){
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true
  ]);
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ($code>=200 && $code<400);
}

/* ---------- PDF (HTML) generation ---------- */
function handleEnhancedPDF($data){
  // The result is an HTML file download to keep hosting simple (no external PDF lib)
  $pages = $data['pages'] ?? [];
  $topic = $data['topic'] ?? 'Story';
  $level = $data['level'] ?? 'A2';
  $userAnswers = $data['userAnswers'] ?? [];

  if (empty($pages)) throw new Exception('No pages for PDF');

  $totalQuestions = count($pages);
  $correct = 0;
  foreach ($userAnswers as $i=>$ua){
    $ans = $pages[$i]['question']['answer'] ?? null;
    if ($ans !== null && $ua === $ans) $correct++;
  }
  $pct = $totalQuestions>0 ? round(($correct/$totalQuestions)*100) : 0;

  $safe = fn($s)=>htmlspecialchars($s??'',ENT_QUOTES,'UTF-8');

  ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title><?= $safe($topic) ?> - Premium ESL Storybook</title>
  <style>
    @page { margin: 1.5cm; size: A4 portrait; }
    body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #2d3748; margin:0; }
    .cover { text-align:center; padding: 3cm 1.5cm; background: linear-gradient(135deg,#667eea,#764ba2); color:#fff; }
    .cover h1 { margin:0 0 12px; }
    .score { background: #f0fff4; border:3px solid #38a169; margin:16px; padding:12px; border-radius:12px; }
    .page { page-break-after: always; padding: 16px; }
    .img { text-align:center; margin:10px 0; }
    .img img { max-width:100%; max-height:12cm; border-radius:10px; box-shadow: 0 6px 18px rgba(0,0,0,.15); }
    .q { background:#fff5f5; border:2px solid #feb2b2; border-radius:12px; padding:12px; }
    .opt { margin: 6px 0; padding: 8px; border-radius:8px; border:1px solid #e2e8f0; }
    .correct { background:#c6f6d5; border-color:#38a169; }
    .user { outline:2px solid #3182ce; }
    .footer { text-align:center; color:#718096; padding: 12px; }
  </style>
</head>
<body>
  <div class="cover">
    <h1>📚 <?= $safe($topic) ?></h1>
    <div>Premium ESL Storybook — Level <?= $safe($level) ?></div>
    <div style="margin-top:10px;">Generated: <?= date('F j, Y \a\t g:i A') ?></div>
  </div>

  <div class="score">
    <strong>Progress:</strong>
    Questions: <?= count($pages) ?> —
    Correct: <?= $correct ?> —
    Score: <?= $pct ?>%
  </div>

  <?php foreach ($pages as $i=>$p): $ans = $p['question']['answer'] ?? 0; $ua = $userAnswers[$i] ?? null; ?>
    <div class="page">
      <h3>📖 Page <?= $i+1 ?> of <?= count($pages) ?></h3>
      <p><?= $safe($p['caption'] ?? '') ?></p>
      <div class="img">
        <?php if (!empty($p['img'])): ?>
          <img src="<?= $safe($p['img']) ?>" alt="illustration page <?= $i+1 ?>">
        <?php else: ?>
          <div style="padding:40px; border:2px dashed #cbd5e0; border-radius:12px;">(Illustration)</div>
        <?php endif; ?>
      </div>
      <?php if (!empty($p['question']['q'])): ?>
        <div class="q">
          <div><strong>Q<?= $i+1 ?>.</strong> <?= $safe($p['question']['q']) ?></div>
          <?php foreach (($p['question']['options'] ?? []) as $idx=>$opt):
            $classes = 'opt';
            if ($idx===$ans) $classes.=' correct';
            if ($ua!==null && $ua===$idx) $classes.=' user';
          ?>
            <div class="<?= $classes ?>"><?= chr(65+$idx) ?>. <?= $safe($opt) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="footer">© <?= date('Y') ?> Story Spark ESL — AI4EduTech</div>
</body>
</html>
<?php
  $html = ob_get_clean();
  header('Content-Type: text/html; charset=UTF-8');
  header('Content-Disposition: attachment; filename="StorySpark-'.preg_replace('/[^a-zA-Z0-9]/','-',$topic).'-'.date('Y-m-d').'.html"');
  header('Cache-Control: no-cache, no-store, must-revalidate');
  echo $html;
}

/* ---------- end ---------- */