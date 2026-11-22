{{-- The identity fields, shared by "Add user" and the user page. Expects $typeChoices and optionally $account. --}}
@php
    $account = $account ?? null;
    $value = fn (string $key) => old($key, $account?->{$key});
    $type = old('user_type', $account?->userType?->user_type_name);
    $text = [
        ['first_name', 'First name'],
        ['middle_name', 'Middle name'],
        ['middle_initial', 'Middle initial'],
        ['last_name', 'Last name'],
        ['suffix', 'Suffix'],
    ];
@endphp

<label for="user_type">Type</label>
<select id="user_type" name="user_type">
    @foreach ($typeChoices as $choice)
        <option value="{{ $choice }}" @selected($type === $choice)>{{ $choice }}</option>
    @endforeach
</select>
@error('user_type')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<label for="student_no">Student number</label>
<input id="student_no" name="student_no" type="text" value="{{ $value('student_no') }}" maxlength="50">
@error('student_no')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<label for="employee_no">Employee number</label>
<input id="employee_no" name="employee_no" type="text" value="{{ $value('employee_no') }}" maxlength="50">
@error('employee_no')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<label for="username">Sign-in number</label>
@if ($account)
    <p class="help">Changing the sign-in number signs the person out of apps.</p>
@else
    <p class="help">Leave blank to use the student or employee number.</p>
@endif
<input id="username" name="username" type="text" value="{{ $value('username') }}" maxlength="255">
@error('username')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<label for="email">Email</label>
<input id="email" name="email" type="email" value="{{ $value('email') }}" maxlength="255">
@error('email')<p class="field-error" role="alert">{{ $message }}</p>@enderror

@foreach ($text as [$key, $label])
    <label for="{{ $key }}">{{ $label }}</label>
    <input id="{{ $key }}" name="{{ $key }}" type="text" value="{{ $value($key) }}" maxlength="{{ in_array($key, ['middle_initial', 'suffix'], true) ? 20 : 255 }}">
    @error($key)<p class="field-error" role="alert">{{ $message }}</p>@enderror
@endforeach
