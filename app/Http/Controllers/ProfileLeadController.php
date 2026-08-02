<?php

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Models\ProfileLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProfileLeadController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        // Honeypot: боти заповнюють приховане поле, люди — ні.
        if (trim((string) $request->input('company', '')) !== '') {
            return response()->json(['ok' => true]); // тихо ігноруємо бота
        }

        if (! Schema::hasTable('profile_leads')) {
            return response()->json(['ok' => false, 'message' => 'Тимчасово недоступно.'], 503);
        }

        $profile = Profile::query()
            ->where('status', 'active')
            ->where('slug', $slug)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:5', 'max:40'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'name' => 'імʼя',
            'phone' => 'телефон',
        ]);

        $lead = ProfileLead::create([
            'profile_id' => $profile->id,
            'name' => trim($validated['name']),
            'phone' => trim($validated['phone']),
            'message' => trim((string) ($validated['message'] ?? '')) ?: null,
            'source' => 'profile_form',
            'status' => 'new',
            'is_read' => false,
            'ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        // Власнику — лише email: у кабінеті заявка й так світиться лічильником
        // біля пункту «Заявки», дублювати її ще й у «Сповіщення» — шум.
        if ($profile->owner_user_id) {
            rescue(fn () => app(\App\Services\Notifications\NewLeadAlertMailer::class)->handle($lead));
        }

        return response()->json([
            'ok' => true,
            'message' => 'Дякуємо! Ваша заявка надіслана — з вами звʼяжуться найближчим часом.',
        ]);
    }
}
