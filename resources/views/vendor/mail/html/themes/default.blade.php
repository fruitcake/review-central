{{--
    The look of the panels, for mail: a band in the sidebar's colour that carries the
    brand above a square white card on a light ground. In the colours of the tenant
    a mail is about, when it says which (as `colours`), and otherwise the installation's.
--}}
@php($c = $colours ?? \App\Filament\Themes::for(null))

/* Base */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif,
        'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol';
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: {{ $c['ground'] }};
    color: {{ $c['text'] }};
    height: 100%;
    line-height: 1.4;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p,
ul,
ol,
blockquote {
    line-height: 1.4;
    text-align: start;
}

a {
    color: {{ $c['text'] }};
}

a img {
    border: none;
}

/* Typography */

h1 {
    color: {{ $c['text'] }};
    font-size: 20px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h2 {
    font-size: 16px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h3 {
    font-size: 14px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

p {
    font-size: 16px;
    line-height: 1.5em;
    margin-top: 0;
    text-align: left;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

/* Layout */

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $c['ground'] }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

/* Header */

.header {
    padding: 32px 0 0;
    text-align: center;
}

.header-band {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: {{ $c['sidebar'] }};
    border-radius: 0;
    margin: 0 auto;
    width: 570px;
}

.header-cell {
    padding: 20px 32px;
    text-align: left;
}

.brand-mark {
    background-color: {{ $c['accent'] }};
    border-radius: 0;
    color: {{ $c['on-accent'] }};
    font-size: 18px;
    font-weight: bold;
    height: 32px;
    line-height: 32px;
    text-align: center;
    width: 32px;
}

.brand-name {
    padding-left: 10px;
}

.brand-name a {
    color: #ffffff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    font-size: 18px;
    font-weight: bold;
    letter-spacing: -0.01em;
    text-decoration: none;
}

/* Logo */

.logo {
    height: 75px;
    margin-top: 15px;
    margin-bottom: 10px;
    max-height: 75px;
    width: 75px;
}

/* Body */

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $c['ground'] }};
    border-bottom: 1px solid {{ $c['ground'] }};
    border-top: 1px solid {{ $c['ground'] }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: #ffffff;
    border-color: {{ $c['border'] }};
    border-radius: 0;
    border-style: solid;
    border-width: 0 1px 1px 1px;
    margin: 0 auto;
    padding: 0;
    width: 570px;
}

.inner-body a {
    word-break: break-all;
}

/* Subcopy */

.subcopy {
    border-top: 1px solid {{ $c['border'] }};
    margin-top: 25px;
    padding-top: 25px;
}

.subcopy p {
    font-size: 14px;
}

/* Footer */

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: {{ $c['muted'] }};
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: {{ $c['muted'] }};
    text-decoration: underline;
}

/* Tables */

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid {{ $c['border'] }};
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: {{ $c['text'] }};
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.content-cell {
    max-width: 100vw;
    padding: 32px;
}

/* Buttons */

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 0;
    color: #fff;
    display: inline-block;
    font-weight: 500;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $c['button'] }};
    border-bottom: 8px solid {{ $c['button'] }};
    border-left: 18px solid {{ $c['button'] }};
    border-right: 18px solid {{ $c['button'] }};
    border-top: 8px solid {{ $c['button'] }};
    color: {{ $c['button-text'] }};
}

.button-green,
.button-success {
    background-color: #15803d;
    border-bottom: 8px solid #15803d;
    border-left: 18px solid #15803d;
    border-right: 18px solid #15803d;
    border-top: 8px solid #15803d;
}

.button-red,
.button-error {
    background-color: {{ $c['danger'] }};
    border-bottom: 8px solid {{ $c['danger'] }};
    border-left: 18px solid {{ $c['danger'] }};
    border-right: 18px solid {{ $c['danger'] }};
    border-top: 8px solid {{ $c['danger'] }};
}

/* Panels */

.panel {
    border-left: {{ $c['text'] }} solid 4px;
    margin: 21px 0;
}

.panel-content {
    background-color: {{ $c['ground'] }};
    color: {{ $c['text'] }};
    padding: 16px;
}

.panel-content p {
    color: {{ $c['text'] }};
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

/* Utilities */

.break-all {
    word-break: break-all;
}
