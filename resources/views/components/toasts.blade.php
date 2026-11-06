<div id="nexora-toasts" class="toast-stack" popover="manual" aria-label="Notifications">
    <div data-toast-list></div>
</div>
<template id="nexora-toast-template">
    <div class="toast" data-tone="info">
        <span class="toast-mark" aria-hidden="true"></span>
        <p data-toast-message></p>
        <button type="button" class="icon-button" aria-label="{{ __('Fermer la notification') }}"><x-icon name="close" class="size-4"/></button>
    </div>
</template>
@foreach (array_filter([
    'success' => session('notice'),
    'error' => $errors->first('login'),
]) as $tone => $message)
    <span hidden data-flash-toast="{{ $message }}" data-tone="{{ $tone }}"></span>
@endforeach
