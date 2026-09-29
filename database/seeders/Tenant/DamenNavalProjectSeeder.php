<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Models\Director;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Example e-learning project: the "Welcome to Damen Naval" safety induction,
 * one shot per course slide. Attached to the first director, or a demo
 * director when none exists.
 */
class DamenNavalProjectSeeder extends Seeder
{
    public function run(): void
    {
        $director = Director::query()->orderBy('id')->first()
            ?? Director::factory()->create(['name' => 'Demo director', 'email' => 'director@example.com']);

        $project = Project::query()->firstOrCreate(
            ['director_id' => $director->id, 'title' => 'Welcome to Damen Naval'],
            [
                'purpose' => ProjectPurpose::E_LEARNING,
                'description' => 'Safety induction for employees, visitors, suppliers and customers arriving at the Damen Naval shipyard. Bilingual (NL/EN), one idea per slide.',
                'aspect_ratio' => AspectRatio::PORTRAIT,
                'default_duration' => 6,
                'style' => [
                    'look' => 'Clean corporate 3D illustration, soft shading, minimal detail, simple facial features, no text in frame',
                    'palette' => 'Navy blue and steel grey shipyard tones, safety yellow accents for vests, signs and highlights, light neutral background',
                    'medium' => '3D illustration',
                    'mood' => 'Calm, reassuring, professional',
                    'references' => [],
                ],
            ],
        );

        if ($project->shots()->exists()) {
            return;
        }

        foreach ($this->shots() as $index => $shot) {
            $project->shots()->create([
                'position' => $index + 1,
                ...$shot,
            ]);
        }
    }

    /**
     * @return list<array{title: string, subject: string, action: string, takeaway: string, notes: string}>
     */
    private function shots(): array
    {
        return [
            [
                'title' => 'Welcome to Damen Naval',
                'subject' => 'A visitor arriving at the Damen Naval main entrance, greeted by a host in a navy jacket',
                'action' => 'The visitor walks up to the entrance, the host steps forward and welcomes them with an open gesture',
                'takeaway' => 'You are welcome here, and your safety is our top priority',
                'notes' => "NL: Welkom! Uw veiligheid is onze hoogste prioriteit!\nEN: Welcome! Your safety is our top priority!",
            ],
            [
                'title' => 'About Damen Naval',
                'subject' => 'A naval vessel in a shipyard dock with workers and cranes',
                'action' => 'Slow reveal of the vessel from bow to stern while crews work on deck and a crane swings a section into place',
                'takeaway' => 'Damen Naval designs, builds, repairs and modifies naval vessels for their whole operational life',
                'notes' => "NL: Wij ontwerpen, bouwen en repareren marineschepen en bieden diensten gedurende hun volledige operationele levensduur.\nEN: We design, build, repair and modify naval vessels and offer services throughout their operational lifetime.",
            ],
            [
                'title' => 'Your safety is important to us',
                'subject' => 'Four people side by side: an employee, a visitor, a supplier with a delivery trolley and a customer',
                'action' => 'Each person steps into frame in turn, then a protective outline draws around the whole group',
                'takeaway' => 'Whoever you are on site, you are part of our responsibility',
                'notes' => "NL: Of u medewerker, bezoeker, leverancier of klant bent: u valt onder onze verantwoordelijkheid.\nEN: Whether you are an employee, visitor, supplier or customer: you are part of our responsibility.",
            ],
            [
                'title' => 'Security',
                'subject' => 'A security officer at the gatehouse desk',
                'action' => 'The officer hands over a key, places a found item in a labelled box, and a small first-aid kit sits ready on the desk',
                'takeaway' => 'Security patrols, handles lost and found, issues keys and gives first aid',
                'notes' => "NL: Beveiliging voert patrouilles uit, beheert gevonden en verloren voorwerpen, verstrekt sleutels en verleent eerste hulp.\nEN: Security carries out patrols, manages lost and found items, issues keys and provides first aid.",
            ],
            [
                'title' => 'See something? Say something!',
                'subject' => 'An employee noticing an unattended bag near a fence',
                'action' => 'The employee pauses, looks at the bag, then turns and speaks to a security officer who nods',
                'takeaway' => 'Report suspicious situations to security',
                'notes' => "NL: Meld verdachte situaties bij de beveiliging.\nEN: Report suspicious situations to security.",
            ],
            [
                'title' => 'Car access',
                'subject' => 'A car at the security barrier with a parking permit on the dashboard',
                'action' => 'The driver lowers the window, the officer checks the permit behind the windscreen, the barrier lifts',
                'takeaway' => 'Cooperate with security checks and keep your parking permit visible',
                'notes' => "NL: Werk mee aan veiligheidscontroles. Houd uw parkeerbewijs zichtbaar achter de voorruit.\nEN: Cooperate with security checks. Keep your parking permit visible behind your windscreen.",
            ],
            [
                'title' => 'Access badge',
                'subject' => 'A visitor wearing an access badge on a lanyard',
                'action' => 'The visitor clips the badge on so it hangs visibly, then a second beat shows them reporting a lost badge at the desk',
                'takeaway' => 'Wear your badge visibly and report loss or damage immediately',
                'notes' => "NL: Draag uw pas zichtbaar en meld verlies of schade onmiddellijk.\nEN: Wear your badge visible, report loss or damage immediately.",
            ],
            [
                'title' => 'Photography and filming',
                'subject' => 'A visitor raising a phone to photograph the dock',
                'action' => 'The visitor lifts the phone, hesitates, lowers it and asks the host, who shows a permission slip',
                'takeaway' => 'Photography and filming are only allowed with permission',
                'notes' => "NL: Dit is alleen toegestaan met toestemming.\nEN: This is only allowed with permission.",
            ],
            [
                'title' => 'Smoking areas',
                'subject' => 'A designated outdoor smoking shelter with a clear sign',
                'action' => 'An employee walks past the building entrance and continues to the shelter before lighting up',
                'takeaway' => 'Smoke only in the designated outdoor shelters',
                'notes' => "NL: Roken is alleen toegestaan in de aangewezen buitenrookruimtes op de aangegeven locaties.\nEN: Smoking is only allowed in designated outdoor shelters at the indicated locations.",
            ],
            [
                'title' => 'In case of emergency',
                'subject' => 'An employee near a small incident, phone in hand',
                'action' => 'The employee first moves away to a safe spot, then dials the emergency number',
                'takeaway' => 'Get yourself to safety first, then call the emergency number',
                'notes' => "NL: Bel indien mogelijk het noodnummer, maar breng uzelf eerst in veiligheid.\nEN: Call the emergency number if you can, but first get yourself to safety.",
            ],
            [
                'title' => 'When calling the emergency number',
                'subject' => 'An employee on the phone, calm, with four icons appearing beside them',
                'action' => 'While speaking, four icons appear one by one: a name tag, a map pin, a warning symbol, a group of people',
                'takeaway' => 'Always state your name, location, nature of the emergency and number of casualties',
                'notes' => "NL: Vermeld altijd uw naam, locatie, aard van de noodsituatie en het aantal slachtoffers.\nEN: Always state your name, location, nature of emergency and number of casualties.",
            ],
            [
                'title' => 'AED location',
                'subject' => 'The reception desk with an AED cabinet on the wall beside it',
                'action' => 'The camera settles on the reception, the AED cabinet is highlighted, a receptionist points to it',
                'takeaway' => 'In case of cardiac arrest, the AED is at the reception',
                'notes' => "NL: In geval van een hartstilstand bevindt de AED zich bij de receptie.\nEN: In case of cardiac arrest, the AED is located at the reception.",
            ],
            [
                'title' => 'Recognise your Emergency Response Officer',
                'subject' => 'An Emergency Response Officer in a yellow vest with a BHV helmet sticker',
                'action' => 'The officer turns towards the viewer, the vest and the helmet sticker are highlighted in turn',
                'takeaway' => 'Look for yellow vests and BHV helmet stickers',
                'notes' => "NL: Let op gele hesjes en helmstickers met 'BHV'.\nEN: Look for yellow vests and 'BHV' helmet stickers.",
            ],
            [
                'title' => 'What to do in case of fire',
                'subject' => 'A visitor and their host in a corridor with a small fire visible behind a door',
                'action' => 'The host guides the visitor away from the fire with a clear hand gesture, the visitor follows',
                'takeaway' => 'Keep yourself safe and follow the instructions of your host or the Emergency Response Officer',
                'notes' => "NL: Zorg voor uw eigen veiligheid en volg de instructies van uw gastheer/-vrouw of de BHV'er.\nEN: Make sure to keep yourself safe and follow the instructions of your host or the Emergency Response Officer.",
            ],
            [
                'title' => 'Do you hear the evacuation signal?',
                'subject' => 'People in a meeting room as an alarm light flashes',
                'action' => 'Everyone stops, stays calm, and stands up as the host points to the exit',
                'takeaway' => 'Stay calm and follow the instructions of your host or the Emergency Response Officer',
                'notes' => "Sound slide in the course, autoplay.\nNL: Blijf rustig en volg de instructies van uw gastheer/-vrouw of de BHV'er.\nEN: Stay calm and follow the instructions of your host or the Emergency Response Officer.",
            ],
            [
                'title' => 'Muster station',
                'subject' => 'The muster station sign at the car park across the street from the building',
                'action' => 'A group crosses the street at the crossing and gathers under the muster station sign',
                'takeaway' => 'The muster station is the car park on the opposite side of the street',
                'notes' => "NL: De verzamelplaats bevindt zich op de parkeerplaats aan de overkant van de straat.\nEN: The muster station is located at the car park on the opposite side of the street.",
            ],
            [
                'title' => 'Know your evacuation route',
                'subject' => 'A floor plan with escape routes mounted on a wall next to the ground-floor meeting rooms',
                'action' => 'A visitor studies the floor plan, traces the route with a finger, then looks towards the marked exit',
                'takeaway' => 'Floor plans with escape routes are on every floor; check the one for your meeting room',
                'notes' => "NL: Plattegronden met vluchtroutes zijn op elke verdieping beschikbaar. Hieronder wordt de vluchtroute voor de vergaderruimtes op de begane grond getoond.\nEN: Floor plans with escape routes are available on every floor. Below the escape route for the meeting rooms on the ground floor is shown.",
            ],
        ];
    }
}
