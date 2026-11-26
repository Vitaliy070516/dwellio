<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API · Dwellio</title>
    @vite(['resources/js/api-docs.js'])
</head>
<body>
{{-- Специфікація — закомічений docs/openapi.json; CI гарантує, що він збігається з кодом. --}}
<div id="swagger-ui" data-spec-url="{{ route('web.api-docs.spec', absolute: false) }}"></div>
</body>
</html>
