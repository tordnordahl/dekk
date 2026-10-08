@props(['id','title','bag'=>'default','reopen'=>false])
<dialog id="{{ $id }}" class="edit-dialog" aria-labelledby="{{ $id }}-title" data-edit-dialog @if($errors->getBag($bag)->any() || $reopen) data-reopen @endif>
<header><h2 id="{{ $id }}-title">{{ $title }}</h2><button type="button" class="dialog-close" data-edit-close aria-label="Lukk">×</button></header>
<div class="edit-dialog-body">
@if($errors->getBag($bag)->any())<div class="notice danger" role="alert"><ul>@foreach($errors->getBag($bag)->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
{{ $slot }}
</div></dialog>
