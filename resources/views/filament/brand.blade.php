{{-- OmniNomad house-style brand mark; currentColor keeps the wordmark readable
     in both Filament theme modes, the ON glyph stays amber. --}}
<div style="display:flex;align-items:center;gap:10px;">
    <span style="width:30px;height:30px;border:1.3px solid #F4B740;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#F4B740;border-radius:6px;box-shadow:0 0 16px rgba(244,183,64,.28),inset 0 0 10px rgba(244,183,64,.08);letter-spacing:.04em;">ON</span>
    <span style="display:flex;flex-direction:column;line-height:1.15;">
        <span style="font-weight:800;font-size:15px;letter-spacing:-.01em;">{{ $name ?? 'Lumina Academy' }}</span>
        <span style="font-size:9px;font-weight:600;letter-spacing:.16em;color:#5BC8DC;text-transform:uppercase;">by OmniNomad</span>
    </span>
</div>
