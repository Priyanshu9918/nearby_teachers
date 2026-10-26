<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Parent / Guardian Consent · Nearby Teacher</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 18px; background: #f1f5f9; color: #172033; font: 17px/1.6 system-ui, sans-serif; }
        main { max-width: 560px; margin: 40px auto; padding: 30px; background: white; border-radius: 20px; box-shadow: 0 12px 40px #17203312; }
        h1 { font-size: 26px; line-height: 1.3; } .brand { color: #2563eb; font-weight: 700; }
        button { width: 100%; padding: 14px; border-radius: 10px; border: 0; font: inherit; font-weight: 600; cursor: pointer; margin-top: 12px; }
        .approve { background: #166534; color: white; } .reject { background: #fee2e2; color: #991b1b; }
        small { color: #526078; }
    </style>
</head>
<body>
<main>
    <p class="brand">Nearby Teacher</p>
    @isset($status)
        <h1>Consent {{ $status }}</h1>
        <p>Your decision has been saved. The learner can check their updated status in the app.</p>
        <p>You can close this page.</p>
    @else
        <h1>Parent / guardian approval</h1>
        <p>Hello {{ $guardianName }},</p>
        <p><strong>{{ $learnerName }}</strong> has listed you as their parent or guardian and is requesting permission to use Nearby Teacher.</p>
        <p>Approving allows the learner to message teachers and institutes and request lesson bookings, subject to the platform’s other requirements.</p>
        <p>Nearby Teacher does not conduct criminal background or credential checks on listings. Please independently assess the suitability and safety of a teacher or institute for the learner.</p>
        <form method="post" action="{{ route('parental-consent.decide', ['token' => $token]) }}">
            @csrf
            <button class="approve" name="decision" value="approve" type="submit">I am the parent / guardian — Approve</button>
            <button class="reject" name="decision" value="reject" type="submit">Reject consent</button>
        </form>
        <p><small>This link expires in 48 hours from creation and can be used once. If you do not recognize this learner, reject the request. Opening the link does not approve consent.</small></p>
    @endisset
</main>
</body>
</html>
