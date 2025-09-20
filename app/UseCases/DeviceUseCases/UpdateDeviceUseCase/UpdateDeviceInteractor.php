<?php

declare(strict_types=1);

namespace App\UseCases\DeviceUseCases\UpdateDeviceUseCase;

use App\Domain\Interfaces\DeviceInterfaces\DeviceFactory;
use App\Domain\Interfaces\DeviceInterfaces\DeviceRepository;
use App\Domain\Interfaces\RackInterfaces\RackRepository;
use App\Domain\Interfaces\ViewModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class UpdateDeviceInteractor implements UpdateDeviceInputPort
{
    /**
     * @param  UpdateDeviceOutputPort  $output
     * @param  DeviceRepository  $deviceRepository
     * @param  RackRepository  $rackRepository
     * @param  DeviceFactory  $deviceFactory
     */
    public function __construct(
        private readonly UpdateDeviceOutputPort $output,
        private readonly DeviceRepository $deviceRepository,
        private readonly RackRepository $rackRepository,
        private readonly DeviceFactory $deviceFactory,
    ) {
    }

    /**
     * @param  UpdateDeviceRequestModel  $request
     * @return ViewModel
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function updateDevice(UpdateDeviceRequestModel $request): ViewModel
    {
        $deviceUpdating = $this->deviceFactory->makeFromPatchRequest($request);

        // Try to get device
        try {
            $device = $this->deviceRepository->getById($deviceUpdating->getId());
        } catch (\Exception $e) {
            return $this->output->noSuchDevice(
                resolve_proxy(UpdateDeviceResponseModel::class, ['device' => $deviceUpdating])
            );
        }

        // User department check
        if (! Gate::allows('departmentCheck', $device->getDepartmentId())) {
            return $this->output->permissionException(
                resolve_proxy(UpdateDeviceResponseModel::class, ['device' => $deviceUpdating])
            );
        }

        $deviceUpdating->setUpdatedBy($request->getUserName());

        // If no units in request data
        if (! count($deviceUpdating->getUnits()->toArray())) {
            $deviceUpdating->setUnits($device->getUnits());
        }

        // If no side in request data
        if (is_null($deviceUpdating->getLocation())) {
            $deviceUpdating->setLocation($device->getLocation());
        }

        $rack = $this->rackRepository->getById($device->getRackId());

        // Check device units exist
        if (! $rack->hasDeviceUnits($deviceUpdating)) {
            return $this->output->noSuchUnits(
                resolve_proxy(UpdateDeviceResponseModel::class, ['device' => $deviceUpdating])
            );
        }

        DB::beginTransaction();

        // Try to update
        try {
            DB::table('racks')
                ->where('id', $device->getRackId())
                ->lockForUpdate()
                ->get();

            $rack = $this->rackRepository->getById($device->getRackId());

            // Delete old units from rack
            $rack->deleteOldBusyUnits($device);

            // Check device can be moved
            if (! $rack->isDeviceMovingValid($device, $deviceUpdating)) {
                return $this->output->unitsAreBusy(
                    resolve_proxy(UpdateDeviceResponseModel::class, ['device' => $deviceUpdating])
                );
            }

            // Add new units to rack
            $rack->addNewBusyUnits($deviceUpdating);

            $this->rackRepository->updateBusyUnits($rack);

            try {
                $deviceUpdating = $this->deviceRepository->update($deviceUpdating);
            } catch (\Exception $e) {
                return $this->output->unableToUpdateDevice(
                    resolve_proxy(UpdateDeviceResponseModel::class, ['device' => $deviceUpdating]),
                    $e
                );
            }
        } catch (\Exception $e) {
            DB::rollback();

            return $this->output->updateFailed(
                resolve_proxy(UpdateDeviceResponseModel::class, ['device' => $deviceUpdating]),
                $e
            );
        }

        DB::commit();

        Log::channel('action_log')->info("Update Device --> pk {$deviceUpdating->getId()}", [
            'old_data' => $device->toArray(),
            'new_data' => $deviceUpdating->toArray(),
            'by_user' => $request->getUserName(),
        ]);

        return $this->output->deviceUpdated(
            resolve_proxy(UpdateDeviceResponseModel::class, ['device' => $deviceUpdating])
        );
    }
}
