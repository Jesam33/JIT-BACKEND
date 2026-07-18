@php
    Theme::layout('jorsas-landing');

    // Page meta
    Theme::set('pageTitle', Theme::get('pageTitle', 'Technology Solutions | Jorsastech'));
    Theme::set('description', Theme::get('description', 'Jorsastech delivers enterprise-grade IT solutions — custom software, managed infrastructure, and digital transformation services.'));
@endphp

{{-- ══════════════════════════════════════════════════════════════════
     SECTION 1 — HERO
     Dark navy hero with eyebrow chip, large headline, paragraph, CTA
     ════════════════════════════════════════════════════════════════ --}}
<section class="jt-hero" id="jt-hero" aria-label="Hero">
    <div class="jt-hero__bg" aria-hidden="true">
        <img src="{{ Theme::asset()->url('images/jt-hero-bg.png') }}" alt="" class="jt-hero__bg-img" loading="eager">
        {{-- Decorative circuit SVG overlay --}}
        <svg class="jt-hero__circuit" viewBox="0 0 1440 600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" preserveAspectRatio="xMidYMid slice">
            <circle cx="1200" cy="120" r="3" fill="#2563EB" opacity="0.6"/>
            <circle cx="900" cy="480" r="2" fill="#2563EB" opacity="0.4"/>
            <circle cx="240" cy="300" r="2.5" fill="#F59E0B" opacity="0.3"/>
            <path d="M1200 120 L1100 120 L1100 200 L900 200" stroke="#2563EB" stroke-width="1" opacity="0.25"/>
            <path d="M900 480 L900 350 L700 350 L700 200 L600 200" stroke="#2563EB" stroke-width="1" opacity="0.2"/>
            <path d="M240 300 L340 300 L340 180 L500 180" stroke="#2563EB" stroke-width="1" opacity="0.18"/>
            <circle cx="1100" cy="120" r="2" fill="#2563EB" opacity="0.5"/>
            <circle cx="700" cy="350" r="2" fill="#2563EB" opacity="0.4"/>
        </svg>
    </div>

    <div class="container jt-hero__inner">
        <span class="jt-eyebrow">Jorsastech Solutions</span>
        <h1 class="jt-hero__headline">
            Your Technology Partner —<br>
            <em>Powerful &amp; Trusted</em>
        </h1>
        <p class="jt-hero__subhead">
            Jorsastech empowers businesses with enterprise software, managed IT infrastructure,
            and end-to-end digital transformation — built for security, scale, and speed.
        </p>
        <a href="#jt-contact" id="jt-hero-cta" class="jt-btn jt-btn-cta jt-btn-cta--hero">
            <span>Get a Free Consultation</span>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        </a>
    </div>
</section>

{{-- ══════════════════════════════════════════════════════════════════
     SECTION 2 — FEATURE STRIP
     Horizontal bar with 6 pillars, icon + one-liner, vertical dividers
     ════════════════════════════════════════════════════════════════ --}}
<section class="jt-feature-strip" id="jt-features" aria-label="Key Capabilities">
    <div class="container">
        <ul class="jt-feature-strip__list" role="list">
            <li class="jt-feature-strip__item">
                <span class="jt-feature-strip__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                </span>
                <span class="jt-feature-strip__label">Enterprise-Grade Security</span>
            </li>
            <li class="jt-feature-strip__item">
                <span class="jt-feature-strip__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15a4.5 4.5 0 004.5 4.5H18a3.75 3.75 0 001.332-7.257 3 3 0 00-3.758-3.848 5.25 5.25 0 00-10.233 2.33A4.502 4.502 0 002.25 15z"/></svg>
                </span>
                <span class="jt-feature-strip__label">Cloud &amp; On-Premises Deployment</span>
            </li>
            <li class="jt-feature-strip__item">
                <span class="jt-feature-strip__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0013.5 3v7.5z"/></svg>
                </span>
                <span class="jt-feature-strip__label">Real-Time Analytics &amp; Insights</span>
            </li>
            <li class="jt-feature-strip__item">
                <span class="jt-feature-strip__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75L16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z"/></svg>
                </span>
                <span class="jt-feature-strip__label">Custom Software Development</span>
            </li>
            <li class="jt-feature-strip__item">
                <span class="jt-feature-strip__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                </span>
                <span class="jt-feature-strip__label">24/7 Managed IT Support</span>
            </li>
            <li class="jt-feature-strip__item">
                <span class="jt-feature-strip__icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                </span>
                <span class="jt-feature-strip__label">Seamless Third-Party Integrations</span>
            </li>
        </ul>
    </div>
</section>

{{-- ══════════════════════════════════════════════════════════════════
     SECTION 3 — ADVANTAGES (Alternating 2-col)
     ════════════════════════════════════════════════════════════════ --}}
<section class="jt-advantages" id="jt-advantages" aria-label="Our Advantages">
    <div class="container">

        {{-- Eyebrow + section heading --}}
        <div class="jt-section-label-wrap">
            <span class="jt-eyebrow jt-eyebrow--light">Our Advantages</span>
            <h2 class="jt-section-title">A complete suite of technology<br>solutions and tools</h2>
            <p class="jt-section-sub">From infrastructure to applications — everything Jorsastech delivers is designed for modern enterprise performance and long-term scale.</p>
        </div>

        {{-- Row 1 — Text LEFT, Image RIGHT --}}
        <div class="jt-adv-row" id="jt-adv-1">
            <div class="jt-adv-row__text">
                <h3 class="jt-adv-row__title">Your data security is under your full control</h3>
                <p class="jt-adv-row__body">
                    With Jorsastech's on-premises and private cloud options, your critical data never leaves your
                    infrastructure. You retain full ownership, access control, and audit visibility — meeting even
                    the strictest regulatory compliance requirements.
                </p>
            </div>
            <div class="jt-adv-row__visual">
                <div class="jt-adv-row__card jt-adv-row__card--light">
                    <img
                        src="{{ Theme::asset()->url('images/jt-adv-security.png') }}"
                        alt="Data security illustration"
                        class="jt-adv-row__img"
                        loading="lazy"
                        width="480"
                        height="380"
                    >
                </div>
            </div>
        </div>

        {{-- Row 2 — Image LEFT, Text RIGHT (reversed) --}}
        <div class="jt-adv-row jt-adv-row--reverse" id="jt-adv-2">
            <div class="jt-adv-row__text">
                <h3 class="jt-adv-row__title">Simple, fast deployment and integration</h3>
                <p class="jt-adv-row__body">
                    Jorsastech solutions are engineered for rapid rollout. Pre-built connectors, documented APIs,
                    and our experienced implementation team mean you're operational in days — not months.
                    All integrations support major ERP, CRM, and cloud platforms.
                </p>
            </div>
            <div class="jt-adv-row__visual">
                <div class="jt-adv-row__card jt-adv-row__card--dark">
                    <img
                        src="{{ Theme::asset()->url('images/jt-adv-integration.png') }}"
                        alt="Integration illustration"
                        class="jt-adv-row__img"
                        loading="lazy"
                        width="480"
                        height="380"
                    >
                </div>
            </div>
        </div>

        {{-- Row 3 — Text LEFT, Image RIGHT --}}
        <div class="jt-adv-row" id="jt-adv-3">
            <div class="jt-adv-row__text">
                <h3 class="jt-adv-row__title">Performance built for mission-critical workloads</h3>
                <p class="jt-adv-row__body">
                    Whether you're processing thousands of transactions per second or running complex analytics
                    at scale, Jorsastech infrastructure is optimized for reliability, low latency, and horizontal
                    scalability — with full customization for your specific workload profile.
                </p>
            </div>
            <div class="jt-adv-row__visual">
                <div class="jt-adv-row__card jt-adv-row__card--light">
                    <img
                        src="{{ Theme::asset()->url('images/jt-adv-performance.png') }}"
                        alt="Performance illustration"
                        class="jt-adv-row__img"
                        loading="lazy"
                        width="480"
                        height="380"
                    >
                </div>
            </div>
        </div>

    </div>
</section>

{{-- ══════════════════════════════════════════════════════════════════
     SECTION 4 — DIFFERENCE IN APPROACH
     Dark rounded card, left text, right process funnel + stat callout
     ════════════════════════════════════════════════════════════════ --}}
<section class="jt-approach" id="jt-approach" aria-label="Our Approach">
    <div class="container">
        <div class="jt-approach__card">
            <div class="jt-approach__left">
                <h2 class="jt-approach__title">The Jorsastech<br>difference</h2>
                <p class="jt-approach__body">
                    You don't need to build technology from scratch or manage vendor sprawl. Jorsastech delivers
                    ready-made, enterprise-tested platforms that are fully operational immediately after deployment —
                    saving you months of development time and budget.
                </p>
                <a href="#jt-contact" class="jt-btn jt-btn-ghost" id="jt-approach-cta">
                    <span>Book a Discovery Call</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            </div>

            <div class="jt-approach__right">
                {{-- Typical project path boxes --}}
                <div class="jt-approach__funnel-label">Typical project without Jorsastech</div>
                <div class="jt-approach__boxes">
                    <div class="jt-approach__box">
                        <strong>Vendor selection &amp; procurement</strong>
                    </div>
                    <div class="jt-approach__box">
                        <strong>In-house team assembly &amp; development</strong>
                    </div>
                    <div class="jt-approach__box">
                        <strong>Integration, testing &amp; go-live management</strong>
                    </div>
                </div>

                {{-- Funnel lines SVG --}}
                <svg class="jt-approach__funnel-svg" viewBox="0 0 440 80" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M73 0 Q73 40 220 75" stroke="rgba(255,255,255,0.2)" stroke-width="1.5"/>
                    <path d="M220 0 Q220 40 220 75" stroke="rgba(255,255,255,0.2)" stroke-width="1.5"/>
                    <path d="M367 0 Q367 40 220 75" stroke="rgba(255,255,255,0.2)" stroke-width="1.5"/>
                </svg>

                {{-- Stat callout --}}
                <div class="jt-approach__stat">
                    <div class="jt-approach__stat-badge">3× Faster</div>
                    <div class="jt-approach__stat-title">Jorsastech Managed Delivery</div>
                    <div class="jt-approach__stat-sub">
                        Pre-built platform + expert team = production-ready in weeks, not quarters
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════════════════════════════════════════════════════════
     SECTION 5 — WHO WE SERVE (4-col icon grid)
     ════════════════════════════════════════════════════════════════ --}}
<section class="jt-who" id="jt-who" aria-label="Who We Serve">
    <div class="container">
        <h2 class="jt-who__title">Who Jorsastech serves</h2>

        <div class="jt-who__grid" role="list">

            <article class="jt-who__card" role="listitem">
                <div class="jt-who__icon" aria-hidden="true">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                </div>
                <h3 class="jt-who__card-title">Government &amp; Public Sector</h3>
                <p class="jt-who__card-body">
                    Helping agencies digitise services, manage citizen data securely, and improve operational
                    transparency with compliant, auditable systems.
                </p>
            </article>

            <article class="jt-who__card" role="listitem">
                <div class="jt-who__icon" aria-hidden="true">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"/></svg>
                </div>
                <h3 class="jt-who__card-title">Finance &amp; Regulated Industries</h3>
                <p class="jt-who__card-body">
                    Banks, insurers, and fintech companies that require SOC 2, PCI-DSS, or GDPR-compliant
                    infrastructure with full data sovereignty and audit trails.
                </p>
            </article>

            <article class="jt-who__card" role="listitem">
                <div class="jt-who__icon" aria-hidden="true">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
                </div>
                <h3 class="jt-who__card-title">Healthcare &amp; Life Sciences</h3>
                <p class="jt-who__card-body">
                    Clinical data management, patient portal infrastructure, and HIPAA-ready platforms that
                    keep sensitive health records secure and accessible only to authorised personnel.
                </p>
            </article>

            <article class="jt-who__card" role="listitem">
                <div class="jt-who__icon" aria-hidden="true">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253M3 12a8.959 8.959 0 01.284-2.253"/></svg>
                </div>
                <h3 class="jt-who__card-title">Enterprises with Global Operations</h3>
                <p class="jt-who__card-body">
                    Multi-region businesses that need consistent, low-latency infrastructure across offices,
                    with centralised management and localised data residency compliance.
                </p>
            </article>

        </div>
    </div>
</section>

{{-- ══════════════════════════════════════════════════════════════════
     SECTION 6 — CONTACT (awards-jorsas inspired)
     Left: tag + heading + form | Right: illustration
     ════════════════════════════════════════════════════════════════ --}}
<section class="jt-contact-section" id="jt-contact" aria-label="Contact Jorsastech">
    <div class="container">
        <div class="jt-contact-section__inner">

            <div class="jt-contact-section__left">
                <div class="jt-tag">
                    <span>Let's collaborate together</span>
                </div>
                <div class="jt-contact-section__title-wrap">
                    <h2 class="jt-contact-section__title">Got a question?</h2>
                    <p class="jt-contact-section__sub">Contact us to learn more or request a demo.</p>
                </div>

                <div class="jt-contact-form-block">
                    {!! Theme::partial('jorsas-contact-form') !!}
                </div>
            </div>

            <div class="jt-contact-section__right">
                <div class="jt-contact-section__visual">
                    <img
                        src="{{ Theme::asset()->url('images/jt-contact-illus.png') }}"
                        alt="Collaboration illustration"
                        class="jt-contact-section__img"
                        loading="lazy"
                        width="500"
                        height="420"
                    >
                </div>
            </div>

        </div>
    </div>
</section>
