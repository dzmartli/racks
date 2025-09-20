<?php

declare(strict_types=1);

namespace App\UseCases\RackUseCases\UpdateRackUseCase;

use App\Domain\Interfaces\RackInterfaces\RackFactory;
use App\Domain\Interfaces\RackInterfaces\RackRepository;
use App\Domain\Interfaces\RoomInterfaces\RoomRepository;
use App\Domain\Interfaces\ViewModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class UpdateRackInteractor implements UpdateRackInputPort
{
    /**
     * @param  UpdateRackOutputPort  $output
     * @param  RackRepository  $rackRepository
     * @param  RoomRepository  $roomRepository
     * @param  RackFactory  $rackFactory
     */
    public function __construct(
        private readonly UpdateRackOutputPort $output,
        private readonly RackRepository $rackRepository,
        private readonly RoomRepository $roomRepository,
        private readonly RackFactory $rackFactory
    ) {
    }

    /**
     * @param  UpdateRackRequestModel  $request
     * @return ViewModel
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function updateRack(UpdateRackRequestModel $request): ViewModel
    {
        $rackUpdating = $this->rackFactory->makeFromPatchRequest($request);

        // Try to get rack
        try {
            $rack = $this->rackRepository->getById($request->getId());
        } catch (\Exception $e) {
            return $this->output->noSuchRack(
                resolve_proxy(UpdateRackResponseModel::class, ['rack' => $rackUpdating])
            );
        }

        // User department check
        if (! Gate::allows('departmentCheck', $rack->getDepartmentId())) {
            return $this->output->permissionException(
                resolve_proxy(UpdateRackResponseModel::class, ['rack' => $rackUpdating])
            );
        }

        // Set nullable props
        $rackUpdating->setUpdatedBy($request->getUserName());

        $rackUpdating->setRoomId($rack->getRoomId());

        $room = $this->roomRepository->getById($rack->getRoomId());

        DB::beginTransaction();

        DB::table('rack')
            ->where('room_id', $room->getId())
            ->lockForUpdate()
            ->get();

        $rackNamesList = $this->rackRepository->getNamesListByRoomId($room->getId());

        // Name check (can not be repeated inside one room)
        if (! $rackUpdating->isNameValid($rackUpdating->getName(), $rackNamesList) &&
            $rack->getName() !== $rackUpdating->getName()
        ) {
            return $this->output->rackNameException(
                resolve_proxy(UpdateRackResponseModel::class, ['rack' => $rackUpdating])
            );
        }

        // Try to update
        try {
            $rackUpdating = $this->rackRepository->update($rackUpdating);
        } catch (\Exception $e) {
            return $this->output->unableToUpdateRack(
                resolve_proxy(UpdateRackResponseModel::class, ['rack' => $rackUpdating]),
                $e
            );
        }

        DB::commit();

        Log::channel('action_log')->info("Update Rack --> pk {$rackUpdating->getId()}", [
            'old_data' => $rack->toArray(),
            'new_data' => $rackUpdating->toArray(),
            'by_user' => $request->getUserName(),
        ]);

        return $this->output->rackUpdated(
            resolve_proxy(UpdateRackResponseModel::class, ['rack' => $rackUpdating])
        );
    }
}
