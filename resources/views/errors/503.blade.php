<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Service temporarily unavailable</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #fafafa;
            color: #18181b;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #09090b; color: #fafafa; }
            .card { background: #18181b; border-color: #27272a; }
        }
        .card {
            max-width: 32rem;
            margin: 1.5rem;
            padding: 2rem;
            background: #ffffff;
            border: 1px solid #e4e4e7;
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            text-align: center;
        }
        h1 { font-size: 1.25rem; margin: 0 0 0.75rem; }
        p { margin: 0.5rem 0; line-height: 1.5; color: #52525b; }
        @media (prefers-color-scheme: dark) { p { color: #a1a1aa; } }
        .badge { font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase; color: #a1a1aa; }
    </style>
</head>
<body>
    <main class="card">
        <p class="badge">503 · Service unavailable</p>
        <h1>Service temporarily unavailable</h1>
        <p>We can&rsquo;t reach the database right now, so the application is briefly unavailable.</p>
        <p>This is usually short-lived &mdash; please try again in a few moments.</p>
    </main>
</body>
</html>
