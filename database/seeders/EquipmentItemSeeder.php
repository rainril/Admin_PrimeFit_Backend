<?php

namespace Database\Seeders;

use App\Models\EquipmentItem;
use Illuminate\Database\Seeder;

// Run with: php artisan db:seed --class=EquipmentItemSeeder
//
// `image_url` points to a LOCAL FLUTTER ASSET path, not a hosted URL.
// Items that came from the same source photo ("Image N" in your list)
// share the same file: assets/equipment/gym_01.jpg ... gym_17.jpg
//
// IMPORTANT: place your 17 photo files in the Flutter project at
// lib's sibling folder `assets/equipment/`, named gym_01.jpg through
// gym_17.jpg (zero-padded, matching "Image 1" -> gym_01.jpg, etc.).
// If your actual files use a different extension (.png/.jpeg) or
// naming, tell me and I'll adjust this seeder's paths to match exactly.
//
// `qty` defaults to 1 for every item since I don't know the real counts --
// correct these afterward via phpMyAdmin or the Edit button once the
// feature is wired up. `location` is left blank for the same reason.
class EquipmentItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // ---- Image 1 ----
            ['barcode' => 'EQ-001', 'name' => 'Two-Tier Dumbbell Storage Rack & Dumbbells', 'category' => 'Strength', 'description' => 'A metal rack holding pairs of fixed-weight dumbbells.', 'image_url' => 'assets/equipment/gym_01.jpg'],
            ['barcode' => 'EQ-002', 'name' => 'Swiss Balls / Stability Balls', 'category' => 'Functional', 'description' => 'Large inflatable exercise balls (purple and red) on the floor.', 'image_url' => 'assets/equipment/gym_01.jpg'],
            ['barcode' => 'EQ-003', 'name' => 'Resistance Loop Bands & Flat Exercise Bands', 'category' => 'Functional', 'description' => 'Elastic bands in various colors hanging from a wall-mounted rack.', 'image_url' => 'assets/equipment/gym_01.jpg'],
            ['barcode' => 'EQ-004', 'name' => 'Agility Cones', 'category' => 'Functional', 'description' => 'Bright orange stacked training cones.', 'image_url' => 'assets/equipment/gym_01.jpg'],
            ['barcode' => 'EQ-005', 'name' => 'Agility Hurdles / Mini Speed Hurdles', 'category' => 'Functional', 'description' => 'Bright green portable hurdles lying on the floor.', 'image_url' => 'assets/equipment/gym_01.jpg'],
            ['barcode' => 'EQ-006', 'name' => 'Electric Floor Fan', 'category' => 'Facility', 'description' => 'Portable standing floor fan.', 'image_url' => 'assets/equipment/gym_01.jpg'],
            ['barcode' => 'EQ-007', 'name' => 'Wall Mirror / Frames', 'category' => 'Facility', 'description' => 'Gym logo wall sign and safety mirror setup.', 'image_url' => 'assets/equipment/gym_01.jpg'],

            // ---- Image 2 ----
            ['barcode' => 'EQ-008', 'name' => '45-Degree Leg Press Machine', 'category' => 'Strength', 'description' => 'Heavy-duty plate-loaded machine with an angled seat and textured diamond-plate footplate.', 'image_url' => 'assets/equipment/gym_02.jpg'],
            ['barcode' => 'EQ-009', 'name' => 'Cast Iron / Olympic Weight Plates', 'category' => 'Strength', 'description' => 'Metal weight plates stored nearby on floor/loading posts.', 'image_url' => 'assets/equipment/gym_02.jpg'],
            ['barcode' => 'EQ-010', 'name' => 'Cable Machine Frame', 'category' => 'Strength', 'description' => 'Selectorized cable machine frame visible in the back left.', 'image_url' => 'assets/equipment/gym_02.jpg'],
            ['barcode' => 'EQ-011', 'name' => 'Digital LED Gym Clock', 'category' => 'Facility', 'description' => 'Wall-mounted digital clock/timer above the glass window.', 'image_url' => 'assets/equipment/gym_02.jpg'],

            // ---- Image 3 ----
            ['barcode' => 'EQ-012', 'name' => 'Stacked Soft Plyometric Foam Boxes', 'category' => 'Functional', 'description' => 'Color-coded Athletic Legacy plyo boxes of varying heights (15", 30", 45", 60").', 'image_url' => 'assets/equipment/gym_03.jpg'],
            ['barcode' => 'EQ-013', 'name' => 'Cast Iron Dumbbells', 'category' => 'Strength', 'description' => 'Heavy dumbbells resting on the floor beside the plyo boxes.', 'image_url' => 'assets/equipment/gym_03.jpg'],

            // ---- Image 4 ----
            ['barcode' => 'EQ-014', 'name' => 'Adjustable Incline Bench', 'category' => 'Strength', 'description' => 'Padded bench with an adjustable backrest.', 'image_url' => 'assets/equipment/gym_04.jpg'],
            ['barcode' => 'EQ-015', 'name' => 'Flat Bench Press Station with Uprights', 'category' => 'Strength', 'description' => 'Dedicated bench press bench with vertical barbell support racks.', 'image_url' => 'assets/equipment/gym_04.jpg'],
            ['barcode' => 'EQ-016', 'name' => 'Cast Iron Dumbbells (Bench Area)', 'category' => 'Strength', 'description' => 'Dumbbells resting on the floor next to the benches.', 'image_url' => 'assets/equipment/gym_04.jpg'],
            ['barcode' => 'EQ-017', 'name' => 'Ab Roller Wheel', 'category' => 'Functional', 'description' => 'Small wheel with side handles resting beneath the incline bench.', 'image_url' => 'assets/equipment/gym_04.jpg'],
            ['barcode' => 'EQ-018', 'name' => 'Plate-Loaded Incline Bench / Machine', 'category' => 'Strength', 'description' => 'Additional bench frame with plate posts in the rear corner.', 'image_url' => 'assets/equipment/gym_04.jpg'],

            // ---- Image 5 ----
            ['barcode' => 'EQ-019', 'name' => "Captain's Chair / Dip & Leg Raise Station", 'category' => 'Strength', 'description' => 'Vertical metal station with padded armrests and handles for dips and vertical knee raises.', 'image_url' => 'assets/equipment/gym_05.jpg'],
            ['barcode' => 'EQ-020', 'name' => 'Dumbbell Storage Rack & Dumbbells (Lower Tier)', 'category' => 'Strength', 'description' => 'Lower tiers of the dumbbell rack holding various pairs of weights.', 'image_url' => 'assets/equipment/gym_05.jpg'],

            // ---- Image 6 ----
            ['barcode' => 'EQ-021', 'name' => 'Stationary Exercise Bikes / Spin Bikes', 'category' => 'Cardio', 'description' => 'Two blue indoor cycling bikes positioned near the window.', 'image_url' => 'assets/equipment/gym_06.jpg'],
            ['barcode' => 'EQ-022', 'name' => 'Elliptical Trainer / Cross Trainer', 'category' => 'Cardio', 'description' => 'A white and black low-impact cardio trainer in the center.', 'image_url' => 'assets/equipment/gym_06.jpg'],

            // ---- Image 7 ----
            ['barcode' => 'EQ-023', 'name' => 'Heavy Punching Bag', 'category' => 'Functional', 'description' => 'Long, black leather/vinyl punching bag suspended from the ceiling.', 'image_url' => 'assets/equipment/gym_07.jpg'],
            ['barcode' => 'EQ-024', 'name' => 'Flat Utility Bench', 'category' => 'Strength', 'description' => 'Padded backless utility bench set against the wall mirror.', 'image_url' => 'assets/equipment/gym_07.jpg'],
            ['barcode' => 'EQ-025', 'name' => 'Wall-Mounted Fan', 'category' => 'Facility', 'description' => 'Electric oscillating fan mounted near the top of the wall.', 'image_url' => 'assets/equipment/gym_07.jpg'],
            ['barcode' => 'EQ-026', 'name' => 'Aerobic Step Platforms', 'category' => 'Functional', 'description' => 'Stacked step risers visible against the back wall.', 'image_url' => 'assets/equipment/gym_07.jpg'],

            // ---- Image 8 ----
            ['barcode' => 'EQ-027', 'name' => 'T-Bar Row Machine / Landmine Row Setup', 'category' => 'Strength', 'description' => 'Floor-anchored lever arm equipped with wide rubber handles.', 'image_url' => 'assets/equipment/gym_08.jpg'],
            ['barcode' => 'EQ-028', 'name' => 'Powerbar Cast Iron Weight Plates', 'category' => 'Strength', 'description' => '50 lb and 35 lb weight plates loaded onto the row machine and on the floor.', 'image_url' => 'assets/equipment/gym_08.jpg'],

            // ---- Image 9 ----
            ['barcode' => 'EQ-029', 'name' => 'Decline Sit-Up / Abdominal Bench', 'category' => 'Functional', 'description' => 'Angled bench with padded leg/foot rollers at the lower end for decline core work.', 'image_url' => 'assets/equipment/gym_09.jpg'],

            // ---- Image 10 ----
            ['barcode' => 'EQ-030', 'name' => 'Aerobic Step Platforms & Risers', 'category' => 'Functional', 'description' => 'Black and white stackable Ensayo step platforms.', 'image_url' => 'assets/equipment/gym_10.jpg'],
            ['barcode' => 'EQ-031', 'name' => 'Soft Weighted Sandbags / Power Bags', 'category' => 'Functional', 'description' => 'Stacked cylindrical Valor Fitness sandbags with handles (labeled #5).', 'image_url' => 'assets/equipment/gym_10.jpg'],
            ['barcode' => 'EQ-032', 'name' => 'Boxing Gloves', 'category' => 'Functional', 'description' => 'Pair of black padded boxing gloves resting on top of the step platforms.', 'image_url' => 'assets/equipment/gym_10.jpg'],
            ['barcode' => 'EQ-033', 'name' => 'Electric Floor Fan & Agility Hurdles', 'category' => 'Facility', 'description' => 'Portable floor fan and green agility hurdles placed nearby.', 'image_url' => 'assets/equipment/gym_10.jpg'],

            // ---- Image 11 ----
            ['barcode' => 'EQ-034', 'name' => 'Pec Deck / Rear Delt Fly Machine', 'category' => 'Strength', 'description' => 'Dual-arm chest fly and rear deltoid machine with a padded backrest and cable/pin stack.', 'image_url' => 'assets/equipment/gym_11.jpg'],
            ['barcode' => 'EQ-035', 'name' => 'Plate Tree / Weight Storage Rack', 'category' => 'Strength', 'description' => 'Heavy A-frame rack holding stacked cast iron weight plates.', 'image_url' => 'assets/equipment/gym_11.jpg'],
            ['barcode' => 'EQ-036', 'name' => 'Barbell Storage Rack & Bars', 'category' => 'Strength', 'description' => 'Wall-adjacent rack holding straight barbells, EZ-curl bars, and a tricep/trap bar.', 'image_url' => 'assets/equipment/gym_11.jpg'],

            // ---- Image 12 ----
            ['barcode' => 'EQ-037', 'name' => 'Seated Leg Extension / Lying Leg Curl Machine', 'category' => 'Strength', 'description' => 'Dual-function leg machine with padded seats and roller pads.', 'image_url' => 'assets/equipment/gym_12.jpg'],
            ['barcode' => 'EQ-038', 'name' => 'Plate Tree & Cast Iron Plates', 'category' => 'Strength', 'description' => 'Vertical weight plate holder loaded with iron plates.', 'image_url' => 'assets/equipment/gym_12.jpg'],
            ['barcode' => 'EQ-039', 'name' => 'Battle Rope', 'category' => 'Functional', 'description' => 'Coiled heavy black workout rope on the floor behind the machine.', 'image_url' => 'assets/equipment/gym_12.jpg'],

            // ---- Image 13 ----
            ['barcode' => 'EQ-040', 'name' => 'Smith Machine / Squat Rack Combo', 'category' => 'Strength', 'description' => 'Heavy steel frame with guided barbell tracks, safety hooks, and multi-level rack stops.', 'image_url' => 'assets/equipment/gym_13.jpg'],
            ['barcode' => 'EQ-041', 'name' => 'Plate Tree / Weight Storage Rack (Foreground)', 'category' => 'Strength', 'description' => 'A-frame weight tree holding large cast iron plates in the foreground.', 'image_url' => 'assets/equipment/gym_13.jpg'],

            // ---- Image 14 ----
            ['barcode' => 'EQ-042', 'name' => 'Multi-Tier Dumbbell Storage Rack', 'category' => 'Strength', 'description' => 'Main multi-tier dumbbell rack holding pairs of fixed dumbbells.', 'image_url' => 'assets/equipment/gym_14.jpg'],
            ['barcode' => 'EQ-043', 'name' => 'Twist Board / Rotational Waist Disc', 'category' => 'Functional', 'description' => 'Circular balance/waist twisting disc on the floor stand.', 'image_url' => 'assets/equipment/gym_14.jpg'],
            ['barcode' => 'EQ-044', 'name' => 'Small Kettlebell & Vinyl Dumbbells', 'category' => 'Strength', 'description' => 'Light training gear sitting on the floor shelf beneath the rack.', 'image_url' => 'assets/equipment/gym_14.jpg'],

            // ---- Image 15 ----
            ['barcode' => 'EQ-045', 'name' => 'Lat Pulldown / Cable Tower Machine', 'category' => 'Strength', 'description' => 'High-pulley cable tower with a weight stack and attached single D-handle attachment.', 'image_url' => 'assets/equipment/gym_15.jpg'],
            ['barcode' => 'EQ-046', 'name' => 'Preacher Curl Bench / Arm Pad', 'category' => 'Strength', 'description' => 'Padded inclined arm rest station set up for arm curls.', 'image_url' => 'assets/equipment/gym_15.jpg'],

            // ---- Image 16 ----
            ['barcode' => 'EQ-047', 'name' => 'Multi-Function Cable Tower Station', 'category' => 'Strength', 'description' => 'Tall cable machine frame with a selectorized weight stack.', 'image_url' => 'assets/equipment/gym_16.jpg'],
            ['barcode' => 'EQ-048', 'name' => 'Cable Attachments (Rope & Bars)', 'category' => 'Strength', 'description' => 'Triceps rope attachment and short cable bars lying at the base on the floor.', 'image_url' => 'assets/equipment/gym_16.jpg'],
            ['barcode' => 'EQ-049', 'name' => 'Single Cast Iron Weight Plate', 'category' => 'Strength', 'description' => 'Weight plate resting against the wall on the floor.', 'image_url' => 'assets/equipment/gym_16.jpg'],

            // ---- Image 17 ----
            ['barcode' => 'EQ-050', 'name' => 'Wall/Ceiling Mounted Pull-Up Bar', 'category' => 'Strength', 'description' => 'High-mounted steel pull-up bar with multi-grip angled handles fixed above the window frame.', 'image_url' => 'assets/equipment/gym_17.jpg'],
        ];

        foreach ($items as $item) {
            EquipmentItem::updateOrCreate(
                ['barcode' => $item['barcode']],
                array_merge(
                    ['qty' => 1, 'status' => 'Available', 'location' => null, 'next_maintenance' => null],
                    $item
                )
            );
        }
    }
}
