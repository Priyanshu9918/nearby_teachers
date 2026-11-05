@extends('site.layout')

@section('documentLanguage', 'en')
@section('documentDirection', 'ltr')
@section('title', 'Privacy Policy')
@section('description', 'Learn how Nearby Teacher collects, uses, shares and protects personal information, and how you can exercise your privacy rights.')
@section('meta')
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ \App\Services\SeoDirectory::url('/privacy-policy') }}">
@endsection

@section('content')
<main class="wrap policy-page">
    <header class="policy-header">
        <a class="policy-home" href="{{ route('website.home') }}">Nearby Teacher</a>
        <p class="policy-eyebrow">Your information and choices</p>
        <h1>Nearby Teacher Privacy Policy</h1>
        <p class="policy-byline">5 Nov 2026 <span aria-hidden="true">·</span> &#64;Prateek Gupta</p>
        <p class="policy-intro">Nearby Teacher helps learners find teachers and institutes near them. This policy explains what personal data the Nearby Teacher app and website collect, why, who sees it, and how you can control it. Nearby Teacher is operated by BSL Ed Tech Private Limited (British School of Language), C-9 LDA Market, BSL Building, Alambagh Bus Stand, Lucknow 226005, India. It covers the mobile apps (Android and iOS) and www.teachers.britishschooloflanguages.com.</p>
    </header>

    <nav class="policy-toc" aria-label="Privacy policy sections">
        <strong>On this page</strong>
        <a href="#information-collected">Information we collect</a>
        <a href="#use-and-public-information">How we use your information</a>
        <a href="#sharing">Who we share it with</a>
        <a href="#children">Children and under-18 accounts</a>
        <a href="#rights-retention-security">Your rights, retention and security</a>
        <a href="#transfers-cookies-contact">Transfers, cookies, changes and contact</a>
    </nav>

    <article class="policy-content">
        <section id="information-collected">
            <h2>1. Information we collect</h2>
            <ul class="policy-list">
                <li><strong>Account details.</strong> Your name, date of birth, and a phone number (verified by a one-time code) or a Google account. If you link both, they sit on one account. We also store your preferred language.</li>
                <li><strong>Profile details (teachers and institutes).</strong> Bio, subjects, lesson modes (online, home tuition, teacher's place), fees, trial settings, city, state and country, optional LinkedIn or website link, and an optional photo. This is public, see section 2.</li>
                <li><strong>Photo.</strong> Any user can add a profile photo. BSL staff review each new photo before anyone else can see it.</li>
                <li><strong>Location.</strong> With your permission, your device's approximate or precise location, used to show teachers and learners near you and to search within 3, 7 or 14 km. You can also type a city instead. We store the location you list on your profile, not a history of where you go.</li>
                <li><strong>Messages.</strong> What you send to other users in the app.</li>
                <li><strong>Bookings and reviews.</strong> Lesson and trial requests, their status, and any rating or comment you leave.</li>
                <li><strong>City feed.</strong> Posts, comments and likes you make.</li>
                <li><strong>Calendar.</strong> If you allow it, the app adds confirmed lessons to your phone calendar. We do not upload your calendar to our servers.</li>
                <li><strong>Support and reports.</strong> Anything you send through Contact BSL, and any report you file about a user, post or message.</li>
                <li><strong>Referrals.</strong> Your referral code and which accounts joined through it.</li>
                <li><strong>Safety and activity records.</strong> When you accepted the safety notice, which teacher phone numbers you viewed each day (to apply the daily limit), and a log of staff access to messages and bookings.</li>
                <li><strong>Usage data.</strong> Pages and profiles viewed, link clicks, and whether you used Android, iOS or the web. We do not use advertising trackers.</li>
            </ul>
            <p>We do not collect payment card or bank details. Nearby Teacher does not process payments.</p>
        </section>

        <section id="use-and-public-information">
            <h2>2. How we use your information, and what is public</h2>
            <p>We use your information to:</p>
            <ul>
                <li>Show learners nearby teachers and institutes, and the reverse.</li>
                <li>Let users message, book and review each other, and send booking emails and notifications.</li>
                <li>Verify your phone number or Google account when you sign in.</li>
                <li>Keep the platform safe: review photos and reports, limit repeated OTP requests, and suspend accounts that break our Terms.</li>
                <li>Rank listings (BSL Verified first, then referral points, then rating) and measure how the service is used, in aggregate.</li>
                <li>Count how many different people each user has contacted, for internal review. This counts conversations, not their content.</li>
            </ul>
            <h3>What is public</h3>
            <p>A teacher's or institute's profile is public, in the app and on a web page created automatically at <a href="https://www.teachers.britishschooloflanguages.com">www.teachers.britishschooloflanguages.com</a>. Search engines can index these pages, including pages for a village, district or subject. Only add details you are happy to be public. Phone numbers are not shown to anonymous visitors: a teacher's number is visible only to signed-in users, up to 5 different teachers per day.</p>
            <p>We do not sell personal data, and we do not use it for third-party advertising.</p>
        </section>

        <section id="sharing">
            <h2>3. Who we share it with</h2>
            <ul class="policy-list">
                <li><strong>BSL staff.</strong> Authorised BSL staff can view any conversation and the list of booking requests on the platform, at any time, not only when something is reported. We do this as a safety measure, because we do not run background checks on teachers and some learners are minors. Every time staff open a conversation or the booking list, the access is logged. Please do not use in-app messages for anything you would not want reviewed for safety.</li>
                <li><strong>Other users.</strong> Your public profile, posts, comments and reviews are visible to others. Your messages are visible to the person you send them to.</li>
                <li><strong>Service providers that help us run the service:</strong>
                    <ul>
                        <li>Google Firebase, for Google sign-in.</li>
                        <li>MSG91 (India) and Twilio (other countries), to send one-time codes by SMS. They receive your phone number and the code.</li>
                        <li>Google Maps, to show maps and nearby results.</li>
                        <li>[Email provider], to send booking and support emails.</li>
                        <li>[Hosting provider and country], where our servers and database run.</li>
                    </ul>
                </li>
                <li><strong>Legal and safety.</strong> We may disclose information if the law requires it, or to protect someone's safety, for example in a credible report involving a child.</li>
            </ul>
            <p>Nearby Teacher connects learners and teachers. What happens in lessons, and any arrangement made between you, is between you and the other person.</p>
        </section>

        <section id="children">
            <h2>4. Children and under-18 accounts</h2>
            <p>We ask for your date of birth when you sign up. Nearby Teacher is not intended for children under [13]. If you are under 18, a parent or guardian must give consent, and we collect their contact details, before you can message or book teachers.</p>
            <p>A parent or guardian can ask to see, correct or delete a child's data by writing to <a href="mailto:teachers@britishschooloflanguages.com">teachers@britishschooloflanguages.com</a>. If we learn we hold data of a child under the minimum age without consent, we will delete it.</p>
        </section>

        <section id="rights-retention-security">
            <h2>5. Your rights, retention and security</h2>
            <h3>Your choices</h3>
            <p>You can edit your profile in the app at any time, and turn off location or calendar access in your phone settings.</p>
            <h3>Access, correction and deletion</h3>
            <p>You can ask us for a copy of your data, to correct it, or to delete your account and its data. Write to <a href="mailto:teachers@britishschooloflanguages.com">teachers@britishschooloflanguages.com</a> or use Contact BSL in the app. We will respond within 30 days. Where you are covered by the Digital Personal Data Protection Act 2023 (India), the GDPR (EU/UK) or similar law, you have the rights those laws give you, including withdrawing consent.</p>
            <h3>Retention</h3>
            <p>We keep account and profile data while your account is active. One-time codes expire after 10 minutes. Messages, bookings and reviews are kept so both sides keep their history, until you ask for deletion. We may keep limited records where the law requires it or to handle a safety report. When your account is deleted, your public profile page is removed.</p>
            <h3>Security</h3>
            <p>One-time codes are stored only as a hash. Data travels over HTTPS. Access to the database is limited to authorised staff. No system is perfectly secure, so please protect your phone and account.</p>
        </section>

        <section id="transfers-cookies-contact">
            <h2>6. Transfers, cookies, changes and contact</h2>
            <h3>International transfers</h3>
            <p>Nearby Teacher is used in many countries. Your data is processed on servers in [country], and by the providers in section 3, which may be in other countries. We use contracts and provider safeguards to protect it when it moves.</p>
            <h3>Cookies</h3>
            <p>The website stores a language preference and logs page views. It does not use advertising cookies. The apps do not use advertising trackers.</p>
            <h3>Changes</h3>
            <p>If we change this policy in a material way, we will tell you in the app or on the website before it takes effect. The date at the top shows the latest version.</p>
            <h3>Contact and Grievance Officer</h3>
            <address>
                BSL Ed Tech Private Limited, C-9 LDA Market, BSL Building, Alambagh Bus Stand, Lucknow 226005, India<br>
                Email: <a href="mailto:teachers@britishschooloflanguages.com">teachers@britishschooloflanguages.com</a><br>
                Phone: <a href="tel:+918960000014">+91 89600 00014</a><br>
                Grievance Officer: [name], same address and email. We will acknowledge complaints within 24 hours and resolve them within 15 days.
            </address>
            <p class="policy-governing-law">This policy is governed by the laws of India.</p>
        </section>
    </article>
</main>
<style>
    .policy-page{max-width:900px;padding-top:24px}
    .policy-header{padding:16px 0 22px}
    .policy-home{display:inline-flex;margin-bottom:25px;color:var(--app-primary);font-size:13px;font-weight:700;text-decoration:none}
    .policy-home:hover{text-decoration:underline}
    .policy-eyebrow{margin:0 0 8px;color:var(--app-map-radius);font-size:11px;font-weight:750;letter-spacing:.1em;text-transform:uppercase}
    .policy-header h1{font-size:clamp(30px,5vw,42px);line-height:1.12;letter-spacing:-.04em}
    .policy-byline{margin:10px 0 20px;color:var(--app-text-secondary);font-size:13px}
    .policy-byline span{padding:0 5px}
    .policy-intro{max-width:780px;margin:0;color:#46556a;font-size:15px;line-height:1.8}
    .policy-toc{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 18px;margin:16px 0 22px;padding:20px 22px;border:1px solid var(--app-border);border-radius:14px;background:#f5f8fc}
    .policy-toc strong{grid-column:1/-1;color:#243b59;font-size:13px}
    .policy-toc a{color:#315c91;font-size:12px;line-height:1.5;text-decoration:none}
    .policy-toc a:hover{text-decoration:underline}
    .policy-content{padding:30px clamp(20px,5vw,48px);border:1px solid var(--app-border);border-radius:16px;background:var(--app-surface);box-shadow:0 8px 30px #102b4e08}
    .policy-content section{scroll-margin-top:20px}
    .policy-content section+section{margin-top:34px;padding-top:28px;border-top:1px solid var(--app-border)}
    .policy-content h2{margin:0 0 14px;color:#172f50;font-size:21px;line-height:1.35;letter-spacing:-.02em}
    .policy-content h3{margin:22px 0 8px;color:#243b59;font-size:15px;line-height:1.45}
    .policy-content p,.policy-content li,.policy-content address{color:#46556a;font-size:14px;line-height:1.8}
    .policy-content p{margin:10px 0}
    .policy-content ul{padding-left:23px;margin:10px 0 16px}
    .policy-content li{padding-left:3px;margin:7px 0}
    .policy-content li::marker{color:#5377a4}
    .policy-content a{color:#245d9f;overflow-wrap:anywhere}
    .policy-content strong{color:#243b59}
    .policy-content address{font-style:normal}
    .policy-governing-law{margin-top:20px!important;padding-top:17px;border-top:1px solid var(--app-border);font-weight:650}
    @media(max-width:600px){.policy-page{padding-top:15px}.policy-header{padding-top:8px}.policy-toc{grid-template-columns:1fr;padding:17px}.policy-content{padding:23px 19px}.policy-content h2{font-size:19px}}
</style>
@endsection
