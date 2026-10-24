@foreach($fields as $field => $details)
    <div class="col hub-field">
        <div class="row hub-field-head">
            <h4 class="medium small grow">{{ $details['label'] }} <span class="muted">· {{ count($details['values']) }}</span></h4>
            @if($details['limit'])<span class="muted xs">{{ $details['hard'] ? 'Limit' : 'Suggested max' }} {{ $details['limit'] }} characters</span>@endif
        </div>
        @foreach($details['values'] as $index => $text)
            @include('client-hub.copy-row', ['text' => $text, 'key' => $prefix.'-'.$field.'-'.$index, 'limit' => $details['limit'], 'hard' => $details['hard']])
        @endforeach
    </div>
@endforeach
