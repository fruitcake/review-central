@props(['url'])
<tr>
<td class="header">
<table class="header-band" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-cell">
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
{{-- The panels draw the mark as an SVG, which mail clients drop. --}}
<td class="brand-mark" width="32" height="32" align="center" valign="middle">&#10003;</td>
<td class="brand-name" valign="middle"><a href="{{ $url }}">{!! $slot !!}</a></td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
