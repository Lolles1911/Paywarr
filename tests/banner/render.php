<?php

/*
 * Renders the real banner HTML (Wizarr::bannerHtml) into small test pages,
 * with the colour variables of the default theme and of a warm dark custom theme.
 *
 *   php render.php <out-dir>
 */

require __DIR__ . '/../shims.php';
foreach (['WizarrException', 'WizarrClient', 'DiscordException', 'DiscordClient', 'Wizarr'] as $class) {
    require __DIR__ . '/../../extensions/Servers/Wizarr/' . $class . '.php';
}

$out = $argv[1] ?? __DIR__ . '/out';
@mkdir($out, 0777, true);

$themes = [
    // Paymenter's default theme, dark mode defaults.
    'default' => '--color-primary:229 100% 64%;--color-neutral:0 0% 17%;--color-base:100 100% 100%;--color-muted:220 0% 53%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%;',
    // A custom theme with a warm dark palette and --color-on-primary.
    'dark' => '--color-primary:38 92% 56%;--color-on-primary:24 40% 9%;--color-neutral:25 14% 19%;--color-base:38 40% 94%;--color-muted:30 10% 62%;--color-background:24 18% 7%;--color-background-secondary:24 15% 10%;',
];

$extension = new Paymenter\Extensions\Servers\Wizarr\Wizarr([]);

foreach ($themes as $name => $vars) {
    foreach (['preparing', 'ready', 'trial_over', 'trial'] as $state) {
        $banner = $extension->bannerHtml([
            'state' => $state,
            'key' => 'service-7',
            'inviteUrl' => $state === 'ready' ? 'https://invite.example.com/j/AB12CD' : '',
            'serviceUrl' => '/services/7',
            'shopUrl' => '/#subscriptions',
            'remaining' => '5 days left',
            'statusUrl' => '/wizarr/status',
        ]);
        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>:root{' . $vars . '}body{margin:0;min-height:100vh;font-family:system-ui,sans-serif;background:hsl(var(--color-background));color:hsl(var(--color-base))}'
            . 'main{max-width:60rem;margin:0 auto;padding:4rem 1rem}</style></head><body>'
            . '<main><h1>Invoice #1042</h1><p>Paid. Thank you for your purchase.</p></main>'
            . $banner . '</body></html>';
        file_put_contents("$out/$name-$state.html", $page);
    }
}
echo "written to $out\n";
