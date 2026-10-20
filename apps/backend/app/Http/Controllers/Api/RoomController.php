<?php

namespace App\Http\Controllers\Api;

use App\Models\Room;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Xonalar ro'yxati: ko'rish — har qanday xodim/o'quvchi (taqvim filtri), boshqarish — group.manage. */
class RoomController
{
    public function index(Request $request): JsonResponse
    {
        $rows = Room::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->where('active', true))
            ->withCount(['groups' => fn ($q) => $q->where('active', true)])
            ->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $room = Room::create($data);
        Audit::log('room.create', 'room', $room->id, ['after' => $room->only(['name', 'capacity', 'note', 'active'])]);

        return response()->json(['room' => $room], 201);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate($this->rules($room));
        $before = $room->only(array_keys($data));
        DB::transaction(function () use ($room, $data) {
            $room->update($data);
            // Guruhlardagi nom nusxasi (bot eslatmalaridagi {room}) yangilanadi.
            if (array_key_exists('name', $data)) {
                DB::table('study_groups')->where('room_id', $room->id)->update(['room' => $room->name]);
            }
        });
        Audit::log('room.update', 'room', $room->id, ['before' => $before, 'after' => $room->only(array_keys($data))]);

        return response()->json(['room' => $room]);
    }

    /** Guruhga biriktirilgan xona o'chirilmaydi — faqat nofaol qilinadi. */
    public function destroy(Room $room): JsonResponse
    {
        abort_if(DB::table('study_groups')->where('room_id', $room->id)->exists(), 409,
            "Bu xona guruhlarga biriktirilgan. O'chirish o'rniga nofaol qiling.");
        $room->delete();
        Audit::log('room.delete', 'room', $room->id, ['before' => ['name' => $room->name]]);

        return response()->json(['ok' => true]);
    }

    private function rules(?Room $room = null): array
    {
        $req = $room ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:60', Rule::unique('rooms', 'name')->ignore($room?->id)],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
