<!DOCTYPE html>
{{--
    Interactive API reference, rendered client-side by Scalar (self-hosted at
    public/vendor/scalar/standalone.js — no CDN, works fully offline) from the
    OpenAPI contract the sendtrap/core package ships. Standalone page rather
    than the app layout: Scalar owns the full viewport, including nav and
    search.
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API reference — Sendtrap</title>
    <meta name="description" content="Interactive reference for the Sendtrap API, generated from the OpenAPI 3.1 contract.">
    <link rel="icon" href="/favicon.ico">
</head>
<body style="margin:0">
    @php($scalarConfig = json_encode([
        'theme' => 'default',
        'metaData' => ['title' => 'API reference — Sendtrap'],
        // Override the contract's generic {baseUrl} server with this
        // instance's real URL so "Test Request" works out of the box.
        'servers' => [['url' => config('app.url').'/api', 'description' => 'This instance']],
    ]))
    <script
        id="api-reference"
        data-url="{{ route('docs.api.contract', ['format' => 'yaml'], false) }}"
        data-configuration="{{ $scalarConfig }}"
    ></script>
    <script src="/vendor/scalar/standalone.js"></script>
</body>
</html>
