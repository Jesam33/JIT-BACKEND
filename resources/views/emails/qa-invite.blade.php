@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => 'Your ' . ($tester->event?->name ?? 'QA testing') . ' join link',
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => "You're on the list",
    'subtitle' => $tester->event?->name,
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hi {{ $tester->displayName() }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    Thanks for signing up to test with us. This link is your pass into the testing room. Keep this
    email, because the link is the only thing you need on the day.
  </p>

  @if ($tester->slot)
    <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
      <tr>
        <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#111827;">
          <span style="color:#6b7280;">Your slot</span> &nbsp;<strong>{{ $tester->slot->windowLabel() }}</strong>
          @if ($tester->event?->starts_at)
            <br><span style="color:#6b7280;">Date</span> &nbsp;<strong>{{ $tester->event->starts_at->format('D j M Y') }}</strong>
          @endif
        </td>
      </tr>
    </table>
  @endif

  <p style="margin:20px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    When your slot starts, open this link and you'll go straight into the room. There's no password
    to remember and nothing to install.
  </p>

  @include('emails.partials.button', [
    'url' => $link,
    'label' => 'Open my testing room',
    'color' => $brand['color'],
  ])
  @include('emails.partials.fallback-link', ['url' => $link, 'color' => $brand['color']])

  <div style="height:8px;font-size:0;line-height:0;">&nbsp;</div>

  <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.7;color:#6b7280;">
    A few things that help on the day: join from a laptop or a phone with a working microphone, and
    have the app you're testing open and ready. If the link says your slot hasn't started yet, come
    back at your slot time. If it stops working, contact the team running the session.
  </p>
@endsection

@section('footer')
  <p style="margin:0;">{{ $brand['name'] }} and Jorsas Tech</p>
@endsection
