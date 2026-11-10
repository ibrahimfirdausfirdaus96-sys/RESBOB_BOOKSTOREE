<?php

$title = trim((string)($_GET['title'] ?? 'RESBOB'));
if ($title === '') $title = 'RESBOB';
$title = substr($title, 0, 68);
$safeTitle = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=86400');
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 480 720" role="img" aria-label="Cover <?= $safeTitle ?>">
  <rect width="480" height="720" fill="#173a31"/>
  <rect x="32" y="32" width="416" height="656" fill="none" stroke="#f3c15b" stroke-width="3"/>
  <text x="58" y="98" fill="#f3c15b" font-family="Arial, sans-serif" font-size="24" font-weight="700" letter-spacing="3">RESBOB</text>
  <line x1="58" y1="132" x2="422" y2="132" stroke="#f3c15b" stroke-width="2" opacity=".75"/>
  <foreignObject x="58" y="180" width="364" height="360">
    <div xmlns="http://www.w3.org/1999/xhtml" style="color:#fffaf1;font:700 42px Arial,sans-serif;line-height:1.15;display:flex;height:100%;align-items:center;overflow:hidden;word-break:break-word"><?= $safeTitle ?></div>
  </foreignObject>
  <text x="58" y="630" fill="#cbd9d0" font-family="Arial, sans-serif" font-size="18">BOOK STORE</text>
</svg>
