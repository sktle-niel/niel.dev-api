<?php
// Email notifications via PHPMailer (vendored under app/PHPMailer — no Composer).
// The submission is always saved to MySQL first; sending the email is a
// best-effort step, so a missing/invalid SMTP config never breaks the API.

use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

// A PHPMailer instance pre-configured for SMTP, with the brand "From" set.
function ckx_smtp_mailer(array $mail): PHPMailer
{
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host       = $mail['host'];
    $mailer->SMTPAuth   = true;
    $mailer->Username   = $mail['user'];
    $mailer->Password   = $mail['pass'];
    $mailer->SMTPSecure = $mail['secure'] === 'tls' ? 'tls' : 'ssl';
    $mailer->Port       = $mail['port'];
    $mailer->CharSet    = 'UTF-8';
    $mailer->setFrom($mail['from'], $mail['from_name']);
    return $mailer;
}

function budget_label(string $id, string $custom): string
{
    $map = [
        '3-5k'   => 'PHP 3,000 - 5,000',
        '5-10k'  => 'PHP 5,000 - 10,000',
        '10-20k' => 'PHP 10,000 - 20,000',
        '20-50k' => 'PHP 20,000 - 50,000',
    ];
    if ($id === 'custom') {
        return $custom !== '' ? $custom : 'Custom budget';
    }
    return $map[$id] ?? ($id !== '' ? $id : '—');
}

// Format a downpayment for display: "2000" -> "PHP 2,000".
function dp_label(string $v): string
{
    $v = trim($v);
    return $v !== '' && ctype_digit($v) ? 'PHP ' . number_format((int) $v) : $v;
}

function system_label(string $id): string
{
    return [
        'website' => 'Website / Web System',
        'desktop' => 'Desktop App',
        'mobile'  => 'Mobile App',
    ][$id] ?? ($id !== '' ? $id : '—');
}

function service_label(string $id): string
{
    return [
        'business-website' => 'Business Website',
        'landing-page'     => 'Landing Page',
        'web-system'       => 'Web System / Portal',
        'inventory'        => 'Inventory System',
        'booking'          => 'Booking & Services',
        'other'            => 'Something else',
    ][$id] ?? ($id !== '' ? $id : '—');
}

// Flat label/value list used for the plain-text version of the email.
function request_rows(array $r): array
{
    $isCapstone = ($r['path'] === 'capstone');
    $rows = [
        'Reference' => $r['reference'],
        'For'       => $isCapstone ? 'Capstone / Thesis' : 'Business',
    ];

    if ($isCapstone) {
        $rows['System'] = system_label((string) $r['system_type']);
        if (!empty($r['project_title'])) $rows['Title'] = $r['project_title'];
        if (!empty($r['deadline']))      $rows['Deadline'] = $r['deadline'];
    } else {
        $rows['Need'] = service_label((string) $r['service']);
        if (!empty($r['business_name'])) $rows['Business'] = $r['business_name'];
        if (!empty($r['industry']))      $rows['Industry'] = $r['industry'];
        if (!empty($r['has_existing']))  $rows['Existing site'] = $r['has_existing'];
    }

    $rows['Budget'] = budget_label((string) $r['budget'], (string) $r['custom_budget']);
    if (!empty($r['downpayment'])) $rows['Downpayment'] = dp_label((string) $r['downpayment']);
    $rows['Name']   = $r['name'];
    $rows['Email']  = $r['email'];
    if (!empty($r['phone'])) $rows['Phone'] = $r['phone'];
    if (!empty($r['org']))   $rows[$isCapstone ? 'School' : 'Company'] = $r['org'];

    return $rows;
}

// Render an associative array as an email-safe two-column table.
function render_rows(array $rows): string
{
    $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
    foreach ($rows as $k => $v) {
        $out .= '<tr>'
            . '<td style="padding:10px 0;border-bottom:1px solid #efeee9;color:#6f6f6a;'
            . 'font-size:13px;width:160px;vertical-align:top;">' . htmlspecialchars((string) $k) . '</td>'
            . '<td style="padding:10px 0;border-bottom:1px solid #efeee9;color:#18181b;'
            . 'font-size:14px;font-weight:600;vertical-align:top;">'
            . nl2br(htmlspecialchars((string) $v)) . '</td>'
            . '</tr>';
    }
    return $out . '</table>';
}

function build_email_html(array $r): string
{
    $isCapstone = ($r['path'] === 'capstone');

    $project = ['Type' => $isCapstone ? 'Capstone / Thesis' : 'Business'];
    if ($isCapstone) {
        $project['System']         = system_label((string) $r['system_type']);
        if (!empty($r['project_title'])) $project['Project title'] = $r['project_title'];
        if (!empty($r['deadline']))      $project['Target deadline'] = $r['deadline'];
    } else {
        $project['Needs']          = service_label((string) $r['service']);
        if (!empty($r['business_name'])) $project['Business name'] = $r['business_name'];
        if (!empty($r['industry']))      $project['Industry'] = $r['industry'];
        if (!empty($r['has_existing']))  $project['Existing website'] = $r['has_existing'];
    }
    $project['Budget'] = budget_label((string) $r['budget'], (string) $r['custom_budget']);
    if (!empty($r['downpayment'])) $project['Downpayment'] = dp_label((string) $r['downpayment']);

    $contact = ['Name' => $r['name'], 'Email' => $r['email']];
    if (!empty($r['phone'])) $contact['Phone / Messenger'] = $r['phone'];
    if (!empty($r['org']))   $contact[$isCapstone ? 'School' : 'Company'] = $r['org'];

    $desc = nl2br(htmlspecialchars((string) ($r['description'] ?? ''))) ?: '—';
    $ref  = htmlspecialchars((string) $r['reference']);
    $type = $isCapstone ? 'Capstone / Thesis' : 'Business';
    $when = htmlspecialchars($r['submitted'] ?? '');
    $clientName = htmlspecialchars((string) $r['name']);

    $section = function (string $title, string $inner): string {
        return '<tr><td style="padding:22px 28px 0 28px;">'
            . '<p style="margin:0 0 6px;color:#9a9a96;font-size:11px;letter-spacing:1.5px;'
            . 'text-transform:uppercase;font-family:Arial,Helvetica,sans-serif;">' . $title . '</p>'
            . $inner . '</td></tr>';
    };

    return '
<div style="background:#f4f4f2;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"
         style="max-width:600px;width:100%;background:#ffffff;border:1px solid #eceae5;border-radius:14px;border-collapse:separate;overflow:hidden;">
    <tr>
      <td style="background:#18181b;padding:20px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
          <td style="font-size:17px;font-weight:800;letter-spacing:-0.3px;color:#ffffff;">
            CODEKATHA<span style="color:#c2f000;">X</span>
          </td>
          <td align="right" style="color:#9a9a96;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;">
            Project Inquiry
          </td>
        </tr></table>
      </td>
    </tr>

    <tr>
      <td style="padding:26px 28px 4px 28px;">
        <p style="margin:0 0 4px;color:#18181b;font-size:16px;font-weight:700;">New project inquiry</p>
        <p style="margin:0;color:#6f6f6a;font-size:14px;line-height:1.6;">
          ' . $clientName . ' submitted a request through your website. The full details are below.
        </p>
      </td>
    </tr>

    <tr>
      <td style="padding:18px 28px 0 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
               style="background:#f7f6f3;border:1px solid #eceae5;border-radius:10px;">
          <tr>
            <td style="padding:12px 16px;">
              <span style="color:#6f6f6a;font-size:12px;">Reference</span><br>
              <span style="color:#18181b;font-size:15px;font-weight:700;">' . $ref . '</span>
            </td>
            <td style="padding:12px 16px;border-left:1px solid #eceae5;">
              <span style="color:#6f6f6a;font-size:12px;">Type</span><br>
              <span style="color:#18181b;font-size:15px;font-weight:700;">' . $type . '</span>
            </td>
            <td style="padding:12px 16px;border-left:1px solid #eceae5;">
              <span style="color:#6f6f6a;font-size:12px;">Submitted</span><br>
              <span style="color:#18181b;font-size:14px;font-weight:600;">' . $when . '</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    ' . $section('Project details', render_rows($project)) . '
    ' . $section('Message', '<div style="color:#18181b;font-size:14px;line-height:1.7;">' . $desc . '</div>') . '
    ' . $section('Client contact', render_rows($contact)) . '

    <tr>
      <td style="padding:22px 28px 26px 28px;">
        <p style="margin:0;color:#6f6f6a;font-size:13px;line-height:1.6;">
          You can reply directly to this email to respond to '
            . htmlspecialchars((string) $r['name']) . '.
        </p>
      </td>
    </tr>

    <tr>
      <td style="background:#f7f6f3;border-top:1px solid #eceae5;padding:14px 28px;color:#9a9a96;font-size:11px;">
        Sent automatically from the CODEKATHAX website &middot; codekathax.online
      </td>
    </tr>
  </table>
</div>';
}

function send_request_email(array $mail, array $r): bool
{
    // Not configured yet → skip silently (data is already in the database).
    if (empty($mail['enabled']) || $mail['user'] === '' || $mail['pass'] === '') {
        return false;
    }

    // Stamp the submission time in Manila time for the email.
    $tz = new DateTimeZone('Asia/Manila');
    $r['submitted'] = (new DateTime('now', $tz))->format('M j, Y · g:i A');

    $html = build_email_html($r);

    $textLines = [];
    foreach (request_rows($r) as $k => $v) {
        $textLines[] = "$k: $v";
    }
    $textLines[] = '';
    $textLines[] = 'Message:';
    $textLines[] = (string) ($r['description'] ?? '—');
    $text = implode("\n", $textLines);

    $isCapstone = ($r['path'] === 'capstone');

    $mailer = ckx_smtp_mailer($mail);
    try {
        $mailer->addAddress($mail['to']);
        if (!empty($r['email'])) {
            $mailer->addReplyTo($r['email'], $r['name'] !== '' ? $r['name'] : $r['email']);
        }

        $mailer->Subject = 'New project inquiry — ' . $r['reference']
            . ' (' . ($isCapstone ? 'Capstone / Thesis' : 'Business') . ')';
        $mailer->isHTML(true);
        $mailer->Body    = $html;
        $mailer->AltBody = $text;

        $mailer->send();
        return true;
    } catch (Throwable $e) {
        error_log('CKX mail failed: ' . $e->getMessage());
        return false;
    }
}

// Build the client-facing confirmation email (thank-you + reference).
function build_client_html(array $r): string
{
    $isCapstone = ($r['path'] === 'capstone');
    $firstName  = htmlspecialchars(strtok((string) $r['name'], ' ') ?: 'there');
    $ref        = htmlspecialchars((string) $r['reference']);

    $summary = ['For' => $isCapstone ? 'Capstone / Thesis' : 'Business'];
    if ($isCapstone) {
        $summary['System'] = system_label((string) $r['system_type']);
        if (!empty($r['project_title'])) $summary['Project'] = $r['project_title'];
    } else {
        $summary['Service'] = service_label((string) $r['service']);
        if (!empty($r['business_name'])) $summary['Business'] = $r['business_name'];
    }
    $summary['Budget'] = budget_label((string) $r['budget'], (string) $r['custom_budget']);
    if (!empty($r['downpayment'])) $summary['Downpayment'] = dp_label((string) $r['downpayment']);

    return '
<div style="background:#f4f4f2;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"
         style="max-width:600px;width:100%;background:#ffffff;border:1px solid #eceae5;border-radius:14px;border-collapse:separate;overflow:hidden;">
    <tr>
      <td style="background:#18181b;padding:20px 28px;">
        <span style="font-size:17px;font-weight:800;letter-spacing:-0.3px;color:#ffffff;">CODEKATHA<span style="color:#c2f000;">X</span></span>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 28px 0 28px;">
        <h1 style="margin:0 0 10px;color:#18181b;font-size:22px;">Thank you, ' . $firstName . '!</h1>
        <p style="margin:0;color:#3f3f46;font-size:15px;line-height:1.7;">
          We have received your project request. Our team will review it and
          <strong>send you a message as soon as possible</strong> &mdash; usually within one business day.
        </p>
      </td>
    </tr>
    <tr>
      <td style="padding:20px 28px 0 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
               style="background:#f7f6f3;border:1px solid #eceae5;border-radius:10px;">
          <tr><td style="padding:14px 18px;">
            <span style="color:#6f6f6a;font-size:12px;">Your reference number</span><br>
            <span style="color:#18181b;font-size:20px;font-weight:800;letter-spacing:0.5px;">' . $ref . '</span>
          </td></tr>
        </table>
        <p style="margin:10px 2px 0;color:#9a9a96;font-size:12px;">Please keep this reference in case you need to follow up.</p>
      </td>
    </tr>
    <tr>
      <td style="padding:22px 28px 0 28px;">
        <p style="margin:0 0 6px;color:#9a9a96;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;">Summary of your request</p>
        ' . render_rows($summary) . '
      </td>
    </tr>
    <tr>
      <td style="padding:22px 28px 28px 28px;">
        <p style="margin:0;color:#3f3f46;font-size:14px;line-height:1.7;">
          Need to add or change something? Just reply to this email and it will reach us directly.
        </p>
        <p style="margin:16px 0 0;color:#18181b;font-size:14px;">&mdash; The CODEKATHAX Team</p>
      </td>
    </tr>
    <tr>
      <td style="background:#f7f6f3;border-top:1px solid #eceae5;padding:14px 28px;color:#9a9a96;font-size:11px;">
        CODEKATHAX &middot; Web &amp; App Services &middot; This is an automated confirmation.
      </td>
    </tr>
  </table>
</div>';
}

function send_client_confirmation(array $mail, array $r): bool
{
    if (empty($mail['enabled']) || $mail['user'] === '' || $mail['pass'] === '') {
        return false;
    }
    if (empty($r['email']) || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $firstName = strtok((string) $r['name'], ' ') ?: 'there';
    $text = "Thank you, $firstName!\n\n"
        . "We have received your project request and will send you a message as soon as possible "
        . "(usually within one business day).\n\n"
        . "Your reference number: " . $r['reference'] . "\n\n"
        . "Need to add something? Just reply to this email.\n\n"
        . "— The CODEKATHAX Team";

    $mailer = ckx_smtp_mailer($mail);
    try {
        $mailer->addAddress($r['email'], $r['name'] !== '' ? $r['name'] : $r['email']);
        // Replies from the client land in the owner inbox.
        $mailer->addReplyTo($mail['to'], $mail['from_name']);

        $mailer->Subject = 'We received your request — ' . $r['reference'] . ' · CODEKATHAX';
        $mailer->isHTML(true);
        $mailer->Body    = build_client_html($r);
        $mailer->AltBody = $text;

        $mailer->send();
        return true;
    } catch (Throwable $e) {
        error_log('CKX client confirm failed: ' . $e->getMessage());
        return false;
    }
}

// Tell a client their project is ~90% done and to get ready for payment.
function send_progress_notify(array $mail, string $siteUrl, array $r): bool
{
    if (empty($mail['enabled']) || $mail['user'] === '' || $mail['pass'] === '') {
        return false;
    }
    if (empty($r['email']) || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $firstName = htmlspecialchars(strtok((string) $r['name'], ' ') ?: 'there');
    $ref       = htmlspecialchars((string) $r['reference']);
    $progress  = (int) $r['progress'];
    $title     = htmlspecialchars($r['business_name'] ?: ($r['project_title'] ?: 'your project'));
    $trackUrl  = htmlspecialchars($siteUrl . '/track?ref=' . rawurlencode((string) $r['reference']));

    $html = '
<div style="background:#f4f4f2;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"
         style="max-width:600px;width:100%;background:#fff;border:1px solid #eceae5;border-radius:14px;border-collapse:separate;overflow:hidden;">
    <tr><td style="background:#18181b;padding:20px 28px;">
      <span style="font-size:17px;font-weight:800;color:#fff;">CODEKATHA<span style="color:#c2f000;">X</span></span>
    </td></tr>
    <tr><td style="padding:28px 28px 0;">
      <h1 style="margin:0 0 10px;color:#18181b;font-size:22px;">Almost there, ' . $firstName . '!</h1>
      <p style="margin:0;color:#3f3f46;font-size:15px;line-height:1.7;">
        Great news — <strong>' . $title . '</strong> is now about <strong>' . $progress . '% complete</strong>.
        We are on the final stretch, so please <strong>get ready for payment</strong> as we wrap things up.
      </p>
    </td></tr>
    <tr><td style="padding:20px 28px 0;">
      <table role="presentation" width="100%" style="background:#f7f6f3;border:1px solid #eceae5;border-radius:10px;">
        <tr><td style="padding:14px 18px;">
          <span style="color:#6f6f6a;font-size:12px;">Progress</span><br>
          <span style="color:#18181b;font-size:20px;font-weight:800;">' . $progress . '%</span>
          <span style="color:#6f6f6a;font-size:12px;"> &middot; Reference ' . $ref . '</span>
        </td></tr>
      </table>
    </td></tr>
    <tr><td style="padding:22px 28px 28px;">
      <a href="' . $trackUrl . '" style="display:inline-block;background:#18181b;color:#fff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:6px;">View your project</a>
      <p style="margin:16px 0 0;color:#6f6f6a;font-size:13px;line-height:1.6;">
        Reply to this email anytime if you have questions about payment or the final details.
      </p>
    </td></tr>
    <tr><td style="background:#f7f6f3;border-top:1px solid #eceae5;padding:14px 28px;color:#9a9a96;font-size:11px;">
      CODEKATHAX &middot; Project Update
    </td></tr>
  </table>
</div>';

    $text = "Almost there, $firstName!\n\n"
        . "Your project is now about {$progress}% complete. We are on the final stretch, "
        . "so please get ready for payment as we wrap things up.\n\n"
        . "Reference: " . $r['reference'] . "\n"
        . "Track it: " . $siteUrl . '/track?ref=' . rawurlencode((string) $r['reference']) . "\n\n"
        . "Reply to this email anytime.\n\n— The CODEKATHAX Team";

    $mailer = ckx_smtp_mailer($mail);
    try {
        $mailer->addAddress($r['email'], $r['name'] !== '' ? $r['name'] : $r['email']);
        $mailer->addReplyTo($mail['to'], $mail['from_name']);
        $mailer->Subject = 'Your project is almost ready — ' . $r['reference'] . ' · CODEKATHAX';
        $mailer->isHTML(true);
        $mailer->Body    = $html;
        $mailer->AltBody = $text;
        $mailer->send();
        return true;
    } catch (Throwable $e) {
        error_log('CKX progress notify failed: ' . $e->getMessage());
        return false;
    }
}

// Send the client a clean payment receipt once a project is marked completed.
function send_receipt(array $mail, array $r): bool
{
    if (empty($mail['enabled']) || $mail['user'] === '' || $mail['pass'] === '') {
        return false;
    }
    if (empty($r['email']) || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $amount = (float) ($r['deal_amount'] ?? 0);
    $money  = 'PHP ' . number_format($amount, 2);
    $ref    = htmlspecialchars((string) $r['reference']);
    $name   = htmlspecialchars((string) $r['name']);
    $email  = htmlspecialchars((string) $r['email']);
    $title  = htmlspecialchars(
        $r['business_name'] ?: ($r['project_title'] ?: 'Custom software project')
    );
    $tz     = new DateTimeZone('Asia/Manila');
    $datePaid = (new DateTime('now', $tz))->format('M j, Y');

    $html = '
<div style="background:#f4f4f2;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"
         style="max-width:600px;width:100%;background:#fff;border:1px solid #eceae5;border-radius:14px;border-collapse:separate;overflow:hidden;">
    <tr><td style="background:#18181b;padding:20px 28px;">
      <table role="presentation" width="100%"><tr>
        <td style="font-size:17px;font-weight:800;color:#fff;">CODEKATHA<span style="color:#c2f000;">X</span></td>
        <td align="right" style="color:#9a9a96;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;">Receipt</td>
      </tr></table>
    </td></tr>

    <tr><td style="padding:28px 28px 0;">
      <table role="presentation" width="100%"><tr>
        <td style="vertical-align:top;">
          <span style="color:#6f6f6a;font-size:13px;">Amount paid</span><br>
          <span style="color:#18181b;font-size:30px;font-weight:800;letter-spacing:-0.5px;">' . $money . '</span>
        </td>
        <td align="right" style="vertical-align:top;">
          <span style="display:inline-block;background:#eef4e8;color:#2f6b33;font-size:12px;font-weight:700;letter-spacing:0.5px;padding:6px 12px;border-radius:999px;">PAID IN FULL</span>
        </td>
      </tr></table>
    </td></tr>

    <tr><td style="padding:22px 28px 0;">
      <table role="presentation" width="100%" style="background:#f7f6f3;border:1px solid #eceae5;border-radius:10px;">
        <tr>
          <td style="padding:12px 16px;border-right:1px solid #eceae5;">
            <span style="color:#6f6f6a;font-size:12px;">Receipt no.</span><br>
            <span style="color:#18181b;font-size:14px;font-weight:700;">' . $ref . '</span>
          </td>
          <td style="padding:12px 16px;">
            <span style="color:#6f6f6a;font-size:12px;">Date paid</span><br>
            <span style="color:#18181b;font-size:14px;font-weight:700;">' . htmlspecialchars($datePaid) . '</span>
          </td>
        </tr>
      </table>
    </td></tr>

    <tr><td style="padding:22px 28px 0;">
      <p style="margin:0 0 6px;color:#9a9a96;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;">Billed to</p>
      <p style="margin:0;color:#18181b;font-size:14px;font-weight:600;">' . $name . '</p>
      <p style="margin:0;color:#6f6f6a;font-size:13px;">' . $email . '</p>
    </td></tr>

    <tr><td style="padding:20px 28px 0;">
      <table role="presentation" width="100%" style="border-collapse:collapse;">
        <tr>
          <td style="padding:12px 0;border-top:1px solid #efeee9;border-bottom:1px solid #efeee9;color:#18181b;font-size:14px;font-weight:600;">' . $title . '</td>
          <td align="right" style="padding:12px 0;border-top:1px solid #efeee9;border-bottom:1px solid #efeee9;color:#18181b;font-size:14px;font-weight:600;">' . $money . '</td>
        </tr>
        <tr>
          <td style="padding:14px 0 0;color:#18181b;font-size:15px;font-weight:800;">Total paid</td>
          <td align="right" style="padding:14px 0 0;color:#18181b;font-size:15px;font-weight:800;">' . $money . '</td>
        </tr>
      </table>
    </td></tr>

    <tr><td style="padding:22px 28px 28px;">
      <p style="margin:0;color:#3f3f46;font-size:14px;line-height:1.7;">
        Thank you, ' . htmlspecialchars(strtok((string) $r['name'], ' ') ?: 'there') . '! Your payment has been received in full and your project is complete. Reply to this email anytime if you need anything else.
      </p>
    </td></tr>

    <tr><td style="background:#f7f6f3;border-top:1px solid #eceae5;padding:14px 28px;color:#9a9a96;font-size:11px;">
      CODEKATHAX &middot; Web &amp; App Services &middot; This receipt was generated automatically.
    </td></tr>
  </table>
</div>';

    $text = "CODEKATHAX - Receipt\n\n"
        . "Amount paid: $money (PAID IN FULL)\n"
        . "Receipt no.: " . $r['reference'] . "\n"
        . "Date paid: $datePaid\n\n"
        . "Billed to: " . $r['name'] . " (" . $r['email'] . ")\n\n"
        . "Item: " . ($r['business_name'] ?: ($r['project_title'] ?: 'Custom software project')) . "\n"
        . "Total paid: $money\n\n"
        . "Thank you! Your payment has been received in full and your project is complete.\n\n"
        . "- The CODEKATHAX Team";

    $mailer = ckx_smtp_mailer($mail);
    try {
        $mailer->addAddress($r['email'], $r['name'] !== '' ? $r['name'] : $r['email']);
        $mailer->addReplyTo($mail['to'], $mail['from_name']);
        $mailer->Subject = 'Receipt for your project - ' . $r['reference'] . ' - CODEKATHAX';
        $mailer->isHTML(true);
        $mailer->Body    = $html;
        $mailer->AltBody = $text;
        $mailer->send();
        return true;
    } catch (Throwable $e) {
        error_log('CKX receipt mail failed: ' . $e->getMessage());
        return false;
    }
}

// Send a password-reset link. Same email shape for admin and agent accounts;
// the link itself carries the raw (unhashed) token — that's the one place it
// ever leaves the server, and only the sha256 of it lives in the database.
function send_password_reset(array $mail, string $siteUrl, string $email, string $name, string $token): bool
{
    if (empty($mail['enabled']) || $mail['user'] === '' || $mail['pass'] === '') {
        return false;
    }

    $firstName = htmlspecialchars(strtok($name, ' ') ?: 'there');
    $resetUrl  = htmlspecialchars($siteUrl . '/reset-password?token=' . rawurlencode($token));

    $html = '
<div style="background:#f4f4f2;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"
         style="max-width:600px;width:100%;background:#fff;border:1px solid #eceae5;border-radius:14px;border-collapse:separate;overflow:hidden;">
    <tr><td style="background:#18181b;padding:20px 28px;">
      <span style="font-size:17px;font-weight:800;color:#fff;">CODEKATHA<span style="color:#c2f000;">X</span></span>
    </td></tr>
    <tr><td style="padding:28px 28px 0;">
      <h1 style="margin:0 0 10px;color:#18181b;font-size:22px;">Reset your password, ' . $firstName . '</h1>
      <p style="margin:0;color:#3f3f46;font-size:15px;line-height:1.7;">
        We received a request to reset the password on your account. This link is valid for
        ' . CKX_RESET_TOKEN_MIN . ' minutes and can only be used once.
      </p>
    </td></tr>
    <tr><td style="padding:22px 28px 28px;">
      <a href="' . $resetUrl . '" style="display:inline-block;background:#18181b;color:#fff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:6px;">Choose a new password</a>
      <p style="margin:16px 0 0;color:#6f6f6a;font-size:13px;line-height:1.6;">
        If you did not request this, you can safely ignore this email — your password will not change.
      </p>
    </td></tr>
    <tr><td style="background:#f7f6f3;border-top:1px solid #eceae5;padding:14px 28px;color:#9a9a96;font-size:11px;">
      CODEKATHAX &middot; Account Security
    </td></tr>
  </table>
</div>';

    $text = "Reset your password, $firstName\n\n"
        . "We received a request to reset the password on your account. This link is valid for "
        . CKX_RESET_TOKEN_MIN . " minutes and can only be used once.\n\n"
        . $siteUrl . '/reset-password?token=' . rawurlencode($token) . "\n\n"
        . "If you did not request this, you can safely ignore this email — your password will not change.\n\n"
        . "— The CODEKATHAX Team";

    $mailer = ckx_smtp_mailer($mail);
    try {
        $mailer->addAddress($email, $name !== '' ? $name : $email);
        $mailer->Subject = 'Reset your password · CODEKATHAX';
        $mailer->isHTML(true);
        $mailer->Body    = $html;
        $mailer->AltBody = $text;
        $mailer->send();
        return true;
    } catch (Throwable $e) {
        error_log('CKX password reset mail failed: ' . $e->getMessage());
        return false;
    }
}

// Email an agent that their application was approved, with their link + login.
// $maxPct is the commission ceiling (per-client rate is set by the owner per deal).
function send_agent_approved(array $mail, string $siteUrl, array $agent, int $maxPct = 30): bool
{
    if (empty($mail['enabled']) || $mail['user'] === '' || $mail['pass'] === '') {
        return false;
    }
    if (empty($agent['email']) || !filter_var($agent['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $firstName = htmlspecialchars(strtok((string) $agent['name'], ' ') ?: 'there');
    $refLink   = htmlspecialchars($siteUrl . '/?ref=' . $agent['ref_token']);
    $loginUrl  = htmlspecialchars($siteUrl . '/login');

    $html = '
<div style="background:#f4f4f2;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" align="center" width="600" cellpadding="0" cellspacing="0"
         style="max-width:600px;width:100%;background:#fff;border:1px solid #eceae5;border-radius:14px;border-collapse:separate;overflow:hidden;">
    <tr><td style="background:#18181b;padding:20px 28px;">
      <span style="font-size:17px;font-weight:800;color:#fff;">CODEKATHA<span style="color:#c2f000;">X</span></span>
    </td></tr>
    <tr><td style="padding:28px 28px 0;">
      <h1 style="margin:0 0 10px;color:#18181b;font-size:22px;">You are approved, ' . $firstName . '!</h1>
      <p style="margin:0;color:#3f3f46;font-size:15px;line-height:1.7;">
        Your agent account is now active. Share your referral link below with clients —
        you earn up to <strong>' . $maxPct . '%</strong> on every project they sign.
      </p>
    </td></tr>
    <tr><td style="padding:20px 28px 0;">
      <table role="presentation" width="100%" style="background:#f7f6f3;border:1px solid #eceae5;border-radius:10px;">
        <tr><td style="padding:14px 18px;">
          <span style="color:#6f6f6a;font-size:12px;">Your referral link</span><br>
          <span style="color:#18181b;font-size:14px;font-weight:700;word-break:break-all;">' . $refLink . '</span>
        </td></tr>
      </table>
    </td></tr>
    <tr><td style="padding:22px 28px 28px;">
      <a href="' . $loginUrl . '" style="display:inline-block;background:#18181b;color:#fff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 22px;border-radius:6px;">Log in to your dashboard</a>
    </td></tr>
    <tr><td style="background:#f7f6f3;border-top:1px solid #eceae5;padding:14px 28px;color:#9a9a96;font-size:11px;">
      CODEKATHAX &middot; Agent Program
    </td></tr>
  </table>
</div>';

    $text = "You are approved, $firstName!\n\n"
        . "Your agent account is now active. Share your referral link with clients —\n"
        . "you earn up to {$maxPct}% on every project they sign.\n\n"
        . "Referral link: " . $siteUrl . '/?ref=' . $agent['ref_token'] . "\n"
        . "Log in: " . $siteUrl . "/login\n\n— CODEKATHAX";

    $mailer = ckx_smtp_mailer($mail);
    try {
        $mailer->addAddress($agent['email'], $agent['name']);
        $mailer->addReplyTo($mail['to'], $mail['from_name']);
        $mailer->Subject = 'You are approved — CODEKATHAX Agent Program';
        $mailer->isHTML(true);
        $mailer->Body    = $html;
        $mailer->AltBody = $text;
        $mailer->send();
        return true;
    } catch (Throwable $e) {
        error_log('CKX approve mail failed: ' . $e->getMessage());
        return false;
    }
}
