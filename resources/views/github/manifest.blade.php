<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Creating your GitHub App…</title>
    <style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;color:#334}</style>
</head>
<body>
    <form id="manifest" method="post" action="{{ $action }}">
        <input type="hidden" name="manifest" value="{{ $manifest }}">
        <p>Taking you to GitHub to create the Droids App…</p>
        <noscript><button type="submit">Continue to GitHub</button></noscript>
    </form>
    <script>document.getElementById('manifest').submit();</script>
</body>
</html>
