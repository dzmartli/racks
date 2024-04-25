<?php

namespace App\Repositories;

use App\Domain\Interfaces\RoomInterfaces\RoomBusinessRules;
use App\Domain\Interfaces\RoomInterfaces\RoomEntity;
use App\Domain\Interfaces\RoomInterfaces\RoomModel;
use App\Domain\Interfaces\RoomInterfaces\RoomRepository;
use App\Models\Room;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RoomDatabaseRepository implements RoomRepository
{
    /**
     * @param  int  $id
     * @return RoomEntity|RoomBusinessRules|RoomModel
     */
    public function getById(int $id): RoomEntity|RoomBusinessRules|RoomModel
    {
        return Room::where('id', $id)
            ->get()
            ->firstOrFail();
    }

    /**
     * @param  int  $buildingId
     * @return array<string>
     */
    public function getNamesListByBuildingId(int $buildingId): array
    {
        return Room::where('building_id', $buildingId)
            ->pluck('name')
            ->toArray();
    }

    /**
     * @param  RoomEntity|RoomBusinessRules|RoomModel  $room
     * @return RoomEntity|RoomBusinessRules|RoomModel
     */
    public function create(RoomEntity|RoomBusinessRules|RoomModel $room): RoomEntity|RoomBusinessRules|RoomModel
    {
        return Room::create($room->getAttributeSet()->toArray());
    }

    /**
     * @param  RoomEntity|RoomBusinessRules|RoomModel  $room
     * @return RoomEntity|RoomBusinessRules|RoomModel
     */
    public function update(RoomEntity|RoomBusinessRules|RoomModel $room): RoomEntity|RoomBusinessRules|RoomModel
    {
        return tap(Room::where('id', $room->getId())
            ->first())
            ->update(
                $room->getAttributeSet()->toArray()
            );
    }

    /**
     * @param  RoomEntity  $room
     * @return int
     */
    public function delete(RoomEntity|RoomBusinessRules|RoomModel $room): int
    {
        return Room::where('id', $room->getId())
            ->first()
            ->delete();
    }

    /**
     * @return void
     */
    public function lockTable(): void
    {
        DB::table('room')
            ->sharedLock();
    }

    /**
     * @param  string|null  $perPage
     * @return LengthAwarePaginator
     */
    public function getAll(?string $perPage): LengthAwarePaginator
    {
        return Room::paginate($perPage);
    }

    /**
     * @param  string|null  $id
     * @return array<array{
     *     region_name: string,
     *     department_name: string,
     *     site_name: string,
     *     building_name: string
     * }>
     */
    public function getLocation(?string $id): array
    {
        return DB::table('rooms')
            ->where('rooms.id', $id)
            ->select(
                'regions.name as region_name',
                'departments.name as department_name',
                'sites.name as site_name',
                'buildings.name as building_name',
            )
            ->join('buildings', 'rooms.building_id', '=', 'buildings.id')
            ->join('sites', 'buildings.site_id', '=', 'sites.id')
            ->join('departments', 'sites.department_id', '=', 'departments.id')
            ->join('regions', 'departments.region_id', '=', 'regions.id')
            ->get()
            ->toArray();
    }
}
