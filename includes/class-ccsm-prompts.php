<?php
defined('ABSPATH') || exit;
final class CCSM_Prompts {
    public static function scenes(): array {
        return [
            'spring'=>'Fonte de águas e montanhas',
            'road'=>'Estrada e linha de chegada',
            'sky'=>'Céu luminoso e luz celestial',
            'mountains'=>'Montanhas e vale',
            'city'=>'Cidade e profundidade atmosférica',
            'custom'=>'Personalizado',
        ];
    }
    public static function background(array $campaign, string $format): string {
        $settings = self::scenes();
        $scene = $campaign['scene'] ?? 'sky';
        $base = [
            'spring'=>'A crystalline spring flowing over wet dark stone rocks into a beautiful reflective stream, layered blue mountains and peaceful lake in the distance, atmospheric haze, feeling of inexhaustible living water.',
            'road'=>'A blue asphalt road stretching toward a luminous horizon, a clearly visible race finish line on the road, distant hills and mountains, dramatic cinematic perspective, subtle trees.',
            'sky'=>'A luminous open blue sky with soft clouds, distant layered blue mountains and subtle urban skyline, rays breaking through upper clouds, sense of hope and mission.',
            'mountains'=>'Layered azure mountain ridges, a serene valley in mist, a radiant sky with scattered clouds and beautiful sun beams.',
            'city'=>'A subtle city skyline seen from distant mountains, a bright open blue sky, softened clouds, blue atmospheric depth, tranquil and spiritual.',
            'custom'=>'A soft luminous blue landscape, dramatic clouds and atmospheric depth.',
        ];
        $mood = [
            'sober'=>'Understated and minimal, restrained glow, sophisticated clean atmosphere, soft gradients.',
            'vivid'=>'Expressive bright lighting, vivid royal blues, high cinematic dynamic range.',
            'balanced'=>'Cinematic yet elegant, strong readability with luminous and calm blues.',
        ];
        $direction = $format==='portrait'
          ? 'Vertical composition 2:3. Keep the upper and middle 65% simple, with a dark-blue negative-space region where large text will later be overlaid. Put beautiful scenic detail in the lower third.'
          : 'Landscape composition 3:2. Keep the central-left two thirds dark blue and uncluttered for very large title text. Place scenic highlights mostly in the right and lower edge.';
        $extra = trim((string)($campaign['custom_scene']??''));
        $prompt = 'Background artwork ONLY for a premium Brazilian church multimedia design. ' .
            ($base[$scene]??$base['sky']) . ' ' . ($mood[$campaign['mood']??'balanced']??$mood['balanced']) .
            ' Palette: deep navy blue #000A52, vibrant royal blue #001BE1, white and restrained cyan #3F75FF.' .
            ' Soft clouds and rays of sunlight from above, beautiful dimensional atmosphere. ' . $direction .
            ' Realistic visually rich nature photography aesthetic with refined cinematic compositing, not illustration.' .
            ' Critical: absolutely no text, letters, words, typography, logos, brand marks, symbols, emblems, watermarks, icons, human figures, persons or frames. The actual official logo and text will be applied separately by software.';
        if ($extra) $prompt .= ' Extra scenic reference: ' . $extra . '.';
        return $prompt;
    }
}
