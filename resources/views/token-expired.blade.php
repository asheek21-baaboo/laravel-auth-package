<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Session expired</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.5; color: #1a1a1a; }
        a { color: #0b57d0; }
    </style>
</head>
<body>
    <h1>Session expired</h1>
    <p>Token expired, please log in via SSO.</p>
    <p><a href="{{ $loginUrl }}" rel="noopener noreferrer">Log in via SSO</a></p>
</body>
</html>
