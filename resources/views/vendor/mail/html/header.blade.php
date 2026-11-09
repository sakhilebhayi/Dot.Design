@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (in_array(trim($slot), ['Laravel', 'Dot.Design', config('app.name')], true))
<img src="{{ asset('apple-touch-icon.png') }}" class="logo" alt="" width="40" height="40" style="vertical-align: middle; border-radius: 50%;"> <span style="vertical-align: middle; padding-left: 8px;">Dot.Design</span>
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
