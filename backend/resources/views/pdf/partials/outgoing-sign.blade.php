    <div class="sign-wrapper">
        <div class="sign-area">
            @if($isDecree)
            <table class="decree-sign">
                <tr><td>Ditetapkan di</td><td>: {{ $letter->city ?? 'Bandung' }}</td></tr>
                <tr><td>Pada Tanggal</td><td>: {{ $letter->letter_date?->translatedFormat('d F Y') }}</td></tr>
            </table>
            <p style="margin-top: 6px;">{{ $signerPosition }}{{ $institution?->short_name ? ' ' . $institution->short_name : '' }},</p>
            @else
            <p>{{ $letter->city ?? 'Bandung' }}, {{ $letter->letter_date?->translatedFormat('d F Y') }}</p>
            <p>{{ $signerPosition }}</p>
            @endif
            @if($isFinal)
            <div class="qr">
                <a href="{{ $verifyUrl }}"><img src="{{ $qrSignature }}" width="50" height="50" alt="QR"></a>
            </div>
            @else
            <br><br><br>
            @endif
            <p class="name">{{ $signerName }}</p>
            @if($signerNidn)
                <p class="nidn">NIDN: {{ $signerNidn }}</p>
            @endif
        </div>
        <div class="sign-clear"></div>
    </div>
