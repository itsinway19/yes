<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title></head>
<body style="margin:0;background:#f4f6fb;color:#172033;font-family:Arial,Tahoma,sans-serif;line-height:1.7">
<main style="max-width:600px;margin:32px auto;padding:0 16px">
    <div style="padding:18px 28px;background:#101b3a;color:#fff;border-radius:14px 14px 0 0;font-size:20px;font-weight:700">Builderad</div>
    <div style="padding:32px 28px;background:#fff;border:1px solid #e1e5ee;border-top:0;border-radius:0 0 14px 14px">
        <h1 style="margin:0 0 16px;font-size:23px;line-height:1.4">{{ $title }}</h1>
        <p style="margin:0 0 22px">{{ $intro }}</p>
        @if($url)
            <p style="margin:0 0 22px"><a href="{{ $url }}" style="display:inline-block;padding:11px 20px;background:#3730a3;border-radius:8px;color:#fff;text-decoration:none;font-weight:700">{{ $action }}</a></p>
            <p style="margin:0 0 22px;font-size:12px;overflow-wrap:anywhere">{{ $url }}</p>
        @endif
        <p style="margin:0;color:#526078;font-size:14px">{{ $outro }}</p>
    </div>
</main>
</body>
</html>
