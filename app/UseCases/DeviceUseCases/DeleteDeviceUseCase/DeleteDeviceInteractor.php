<?php

declare(strict_types=1);

namespace App\UseCases\DeviceUseCases\DeleteDeviceUseCase;

use App\Domain\Interfaces\DeviceInterfaces\DeviceRepository;
use App\Domain\Interfaces\RackInterfaces\RackRepository;
use App\Domain\Interfaces\ViewModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class DeleteDeviceInteractor implements DeleteDeviceInputPort
{
    /**
     * @param  DeleteDeviceOutputPort  $output
     * @param  DeviceRepository  $deviceRepository
     * @param  RackRepository  $rackRepository
     */
    public function __construct(
        private readonly DeleteDeviceOutputPort $output,
        private readonly DeviceRepository $deviceRepository,
        private readonly RackRepository $rackRepository
    ) {
    }

    /**
     * @param  DeleteDeviceRequestModel  $request
     * @return ViewModel
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function deleteDevice(DeleteDeviceRequestModel $request): ViewModel
    {
        // Try to get device
        try {
            $device = $this->deviceRepository->getById($request->getId());
        } catch (\Exception $e) {
            return $this->output->noSuchDevice(
                resolve_proxy(DeleteDeviceResponseModel::class, ['device' => null])
            );
        }

        // User department check
        if (! Gate::allows('departmentCheck', $device->getDepartmentId())) {
            return $this->output->permissionException(
                resolve_proxy(DeleteDeviceResponseModel::class, ['device' => $device])
            );
        }

        $rack = $this->rackRepository->getById($device->getRackId());

        DB::beginTransaction();

        // Try to delete
        try {
            DB::table('racks')
                ->where('id', $rack->getId())
                ->lockForUpdate()
                ->get();

            $rack = $this->rackRepository->getById($rack->getId());

            // Delete old units from rack
            $newBusyUnits = $rack->deleteOldBusyUnits($device);

            $this->rackRepository->updateBusyUnits($rack);

            try {
                $this->deviceRepository->delete($device);
            } catch (\Exception $e) {
                return $this->output->unableToDeleteDevice(
                    resolve_proxy(DeleteDeviceResponseModel::class, ['device' => $device]),
                    $e
                );
            }
        } catch (\Exception $e) {
            DB::rollback();

            return $this->output->deletionFailed(
                resolve_proxy(DeleteDeviceResponseModel::class, ['device' => $device]),
                $e
            );
        }

        DB::commit();

        Log::channel('action_log')->info("Delete Device --> pk {$device->getId()}", [
            'deleted_data' => $device->toArray(),
            'by_user' => $request->getUserName(),
        ]);

        return $this->output->deviceDeleted(
            resolve_proxy(DeleteDeviceResponseModel::class, ['device' => $device])
        );
    }
}
