@extends('installation.layout')
@section('title', 'Activate MediFlow')
@section('content')
<section class="card">
    <h1>Activate with your MediFlow Platform account</h1>
    <p>Generate a server activation code in MediFlow Platform and enter it below. This server will use its own installation identity.</p>
    @if($needsLogin)
        <p>An installation administrator already exists. Sign in to reactivate this computer.</p>
        <a class="button" href="{{ route('login') }}">Administrator sign in</a>
    @else
        @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ route('kernelbridge.license.activate') }}">
            @csrf
            <label for="activation_code">Activation code</label>
            <input id="activation_code" name="activation_code" required maxlength="255" autocomplete="off" spellcheck="false" />
            <details>
                <summary>Using an older, installation-bound code?</summary>
                <label for="installation_uuid">Installation ID from MediFlow Platform</label>
                <input id="installation_uuid" name="installation_uuid" maxlength="36" autocomplete="off" spellcheck="false" value="{{ old('installation_uuid') }}" />
                <p class="muted">Leave this blank for newly generated server activation codes.</p>
            </details>
            <label for="device_name">Computer name</label>
            <input id="device_name" name="device_name" maxlength="255" value="{{ old('device_name', 'MediFlow installation') }}" />
            <div class="actions"><button type="submit">Activate and continue to setup</button></div>
        </form>
        @if($platformActivationUrl)<p><a href="{{ $platformActivationUrl }}">Open MediFlow Platform</a></p>@endif
    @endif
</section>
@endsection
