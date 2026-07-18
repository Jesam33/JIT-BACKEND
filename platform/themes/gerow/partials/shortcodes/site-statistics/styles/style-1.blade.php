<section class="fun-fact-area">
    <div class="container">
        <div class="fun-fact-row">
            @foreach ($tabs as $tab)
                @continue(!$tab['data'])
                <div class="fun-fact-item">
                    <div class="stat-number">
                        <span class="odometer" data-count="{{ $tab['data'] }}">0</span>
                        @if ($tab['data'] != (int)$tab['data'])
                            <span class="stat-unit">{{ $tab['unit'] }}</span>
                        @else
                            <span class="stat-unit">{{ $tab['unit'] }}</span>
                        @endif
                    </div>
                    @if ($tab['title'])
                        <p>{!! BaseHelper::clean($tab['title']) !!}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>