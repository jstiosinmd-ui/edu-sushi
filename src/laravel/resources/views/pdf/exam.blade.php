<!doctype html>
<html><head><meta charset="utf-8"><style>
@page{margin:35px 38px 60px}body{font-family:"DejaVu Sans";font-size:10px;color:#452735;line-height:1.45}h1{font-size:18px;color:#9d3656;margin:0 0 8px}h2{font-size:13px}header{border-bottom:2px solid #e99092;padding-bottom:12px;margin-bottom:16px}.meta{font-size:9px;color:#755765}.item{margin-bottom:19px;page-break-inside:avoid}p{orphans:3;widows:3}.stem{font-weight:bold;white-space:pre-line}.option{padding:3px 0 3px 14px}.rationale{white-space:pre-line}.number{color:#9d3656;font-weight:bold}.line{padding:8px 0;border-bottom:1px solid #ead7da}thead{display:table-header-group}tr{page-break-inside:avoid}td{padding:7px}table{width:100%;border-collapse:collapse}th{text-align:left;border-bottom:1px solid #9d3656}.logo{width:52px;float:right}
</style></head><body><header>
<img class="logo" src="{{ public_path('art/20261013_EDU_SushiBrandLogo_v1.0.png') }}">
<h1>SUSHI</h1><div>Clerks Comprehensive Exam Reviewer</div>
<h2>{{ $exam->title }} · {{ ['paper'=>'Exam paper','sheet'=>'Answer sheet','key'=>'Answer key'][$kind] }}</h2>
<div class="meta">Exam code: {{ $exam->code }} · {{ count($exam->items) }} questions · Seed {{ $exam->settings['seed'] }}</div>
</header>
@if($kind !== 'key')<p>Name: ____________________________________ &nbsp; Date: _________________</p>@endif
@if($kind === 'sheet')
<table><thead><tr><th>Item</th><th>Mark one answer</th></tr></thead><tbody>
@foreach($exam->items as $i=>$q)<tr><td>{{ $i+1 }}</td><td>A [ &nbsp; ] &nbsp; B [ &nbsp; ] &nbsp; C [ &nbsp; ] &nbsp; D [ &nbsp; ]</td></tr>@endforeach
</tbody></table>
@else
@foreach($exam->items as $i=>$q)
<div class="item"><div class="meta">{{ $q['id'] }} · {{ $q['subject'] }} · {{ $q['topic'] }}</div>
@if($kind === 'paper')
<p class="stem"><span class="number">{{ $i+1 }}.</span> {{ $q['stem'] }}</p>
@foreach($q['options'] as $j=>$option)<div class="option">{{ chr(65+$j) }}. {{ $option }}</div>@endforeach
@else
<p class="stem">{{ $i+1 }}. {{ $q['key'] }}. {{ $q['options'][ord($q['key'])-65] }}</p>
@if($explanations)<p class="rationale">{{ $q['rationale'] }}</p>
@foreach($q['expl'] as $j=>$text)@if(trim($text ?? '') !== '')<p class="rationale">{{ chr(65+$j) }}: {{ $text }}</p>@endif @endforeach
<p class="meta">Source: {{ strip_tags($q['raw']['Answer Source'] ?? '') }}</p>@endif
@endif
</div>
@endforeach
@endif
</body></html>
