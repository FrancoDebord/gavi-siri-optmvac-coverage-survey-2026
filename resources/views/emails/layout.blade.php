{{-- Gabarit commun des e-mails : tableaux et styles en ligne (Outlook, Gmail, webmails), couleurs de la plateforme. --}}
<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title')</title>
    <style>
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; }
            .px { padding-left: 20px !important; padding-right: 20px !important; }
        }
        a { color: #c10202; }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f3f1f0; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1f1f1f; -webkit-text-size-adjust:100%;">
    {{-- Aperçu affiché dans la boîte de réception. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">@yield('preheader')</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f1f0;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                    {{-- En-tête --}}
                    <tr>
                        <td style="background-color:#c10202; border-radius:12px 12px 0 0; padding:28px 32px;" class="px">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="font-size:12px; letter-spacing:1.5px; text-transform:uppercase; color:#ffd9d9; font-weight:600;">GAVI SIRI · Round 2</td>
                                </tr>
                                <tr>
                                    <td style="padding-top:6px; font-size:22px; line-height:28px; color:#ffffff; font-weight:700;">Enquête de couverture vaccinale</td>
                                </tr>
                                <tr>
                                    <td style="padding-top:4px; font-size:14px; color:#ffe9e9;">Plateforme de suivi de la collecte et de gestion des queries</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    {{-- Corps --}}
                    <tr>
                        <td style="background-color:#ffffff; padding:32px; border-left:1px solid #ece8e6; border-right:1px solid #ece8e6;" class="px">
                            @yield('content')
                        </td>
                    </tr>
                    {{-- Pied --}}
                    <tr>
                        <td style="background-color:#faf8f7; border:1px solid #ece8e6; border-top:0; border-radius:0 0 12px 12px; padding:20px 32px; font-size:12px; line-height:18px; color:#706d6b;" class="px">
                            @yield('footer')
                            <div style="padding-top:8px;">AIRID · African Institute for Research in Infectious Diseases — {{ config('app.name') }}<br>
                                <a href="{{ url('/') }}" style="color:#706d6b;">{{ preg_replace('#^https?://#', '', url('/')) }}</a></div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
