<?php

declare(strict_types=1);

namespace App\UseCases\RoomUseCases\CreateRoomUseCase;

use App\Domain\Interfaces\BuildingInterfaces\BuildingRepository;
use App\Domain\Interfaces\RoomInterfaces\RoomFactory;
use App\Domain\Interfaces\RoomInterfaces\RoomRepository;
use App\Domain\Interfaces\ViewModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CreateRoomInteractor implements CreateRoomInputPort
{
    /**
     * @param  CreateRoomOutputPort  $output
     * @param  BuildingRepository  $buildingRepository
     * @param  RoomRepository  $roomRepository
     * @param  RoomFactory  $roomFactory
     */
    public function __construct(
        private readonly CreateRoomOutputPort $output,
        private readonly BuildingRepository $buildingRepository,
        private readonly RoomRepository $roomRepository,
        private readonly RoomFactory $roomFactory
    ) {
    }

    /**
     * @param  CreateRoomRequestModel  $request
     * @return ViewModel
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function createRoom(CreateRoomRequestModel $request): ViewModel
    {
        $room = $this->roomFactory->makeFromCreateRequest($request);

        // Try to get building
        try {
            $building = $this->buildingRepository->getById($request->getBuildingId());
        } catch (\Exception $e) {
            return $this->output->noSuchBuilding(
                resolve_proxy(CreateRoomResponseModel::class, ['room' => $room])
            );
        }

        // User department check
        if (! Gate::allows('departmentCheck', $building->getDepartmentId())) {
            return $this->output->permissionException(
                resolve_proxy(CreateRoomResponseModel::class, ['room' => $room])
            );
        }

        $room->setUpdatedBy($request->getUserName());

        $room->setDepartmentId($building->getDepartmentId());

        DB::beginTransaction();

        try {
            DB::table('buildings')
                ->where('id', $building->getId())
                ->lockForUpdate()
                ->first();

            $roomNamesList = $this->roomRepository->getNamesListByBuildingId($building->getId());

            // Name check (can not be repeated inside one building)
            if (! $room->isNameValid($room->getName(), $roomNamesList)) {
                DB::rollBack();

                return $this->output->roomNameException(
                    resolve_proxy(CreateRoomResponseModel::class, ['room' => $room])
                );
            }

            $room = $this->roomRepository->create($room);

            $room = $room->fresh([]);

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return $this->output->unableToCreateRoom(
                resolve_proxy(CreateRoomResponseModel::class, ['room' => $room]),
                $e
            );
        }

        Log::channel('action_log')->info("Create Room --> fk {$building->getId()}", [
            'new_data' => $room->toArray(),
            'by_user' => $request->getUserName(),
        ]);

        return $this->output->roomCreated(
            resolve_proxy(CreateRoomResponseModel::class, ['room' => $room])
        );
    }
}
