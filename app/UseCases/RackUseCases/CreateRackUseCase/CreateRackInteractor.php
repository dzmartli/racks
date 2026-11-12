<?php

declare(strict_types=1);

namespace App\UseCases\RackUseCases\CreateRackUseCase;

use App\Domain\Interfaces\RackInterfaces\RackFactory;
use App\Domain\Interfaces\RackInterfaces\RackRepository;
use App\Domain\Interfaces\RoomInterfaces\RoomRepository;
use App\Domain\Interfaces\ViewModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CreateRackInteractor implements CreateRackInputPort
{
    /**
     * @param  CreateRackOutputPort  $output
     * @param  RackRepository  $rackRepository
     * @param  RoomRepository  $roomRepository
     * @param  RackFactory  $rackFactory
     */
    public function __construct(
        private readonly CreateRackOutputPort $output,
        private readonly RackRepository $rackRepository,
        private readonly RoomRepository $roomRepository,
        private readonly RackFactory $rackFactory
    ) {
    }

    /**
     * @param  CreateRackRequestModel  $request
     * @return ViewModel
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function createRack(CreateRackRequestModel $request): ViewModel
    {
        $rack = $this->rackFactory->makeFromCreateRequest($request);

        // Try to get room
        try {
            $room = $this->roomRepository->getById($request->getRoomId());
        } catch (\Exception $e) {
            return $this->output->noSuchRoom(
                resolve_proxy(CreateRackResponseModel::class, ['rack' => $rack])
            );
        }

        // User department check
        if (! Gate::allows('departmentCheck', $room->getDepartmentId())) {
            return $this->output->permissionException(
                resolve_proxy(CreateRackResponseModel::class, ['rack' => $rack])
            );
        }

        $rack->setUpdatedBy($request->getUserName());

        $rack->setDepartmentId($room->getDepartmentId());

        DB::beginTransaction();

        try {
            DB::table('rooms')
                ->where('id', $room->getId())
                ->lockForUpdate()
                ->first();

            $rackNamesList = $this->rackRepository->getNamesListByRoomId($room->getId());

            // Name check (can not be repeated inside one room)
            if (! $rack->isNameValid($rack->getName(), $rackNamesList)) {
                DB::rollBack();

                return $this->output->rackNameException(
                    resolve_proxy(CreateRackResponseModel::class, ['rack' => $rack])
                );
            }

            $rack = $this->rackRepository->create($rack);

            $rack = $rack->fresh([]);

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return $this->output->unableToCreateRack(
                resolve_proxy(CreateRackResponseModel::class, ['rack' => $rack]),
                $e
            );
        }

        Log::channel('action_log')->info("Create Rack --> fk {$room->getId()}", [
            'new_data' => $rack->toArray(),
            'by_user' => $request->getUserName(),
        ]);

        return $this->output->rackCreated(
            resolve_proxy(CreateRackResponseModel::class, ['rack' => $rack])
        );
    }
}
