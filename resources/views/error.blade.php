<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $message }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 3rem auto; padding: 0 1rem; line-height: 1.5; color: #1a1a1a; }
    </style>
</head>
<body>
    <h1>{{ $message }}</h1>
    @if ($description !== null)
        <p>{{ $description }}</p>
    @endif
</body>
</html>
