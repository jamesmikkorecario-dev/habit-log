@php($emailMessages = collect(['emailRequest', 'emailResend', 'emailCancel', 'emailConfirm'])->flatMap(fn ($bag) => $errors->getBag($bag)->all())->unique())
@if($emailMessages->isNotEmpty())
    <div role="alert" tabindex="-1" class="scroll-mt-24 space-y-2 rounded-lg border border-red-900/60 p-4 text-sm text-red-300 focus-visible:outline-2 focus-visible:outline-emerald-300"
        x-data x-init="$nextTick(() => { $el.scrollIntoView({ block: 'center' }); $el.focus({ preventScroll: true }); })">
        <p>Your email has not changed.</p>
        <ul class="list-inside list-disc">@foreach($emailMessages as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif
