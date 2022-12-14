"""
Mixins for business logic calls
"""
import logging
from abc import ABC, abstractmethod
from datetime import datetime
from typing import List, Optional

from django.db.models.base import ModelBase
from django.db.models.query import QuerySet
from django.http import HttpRequest, HttpResponse
from rest_framework.response import Response
from rest_framework.serializers import SerializerMetaclass

from mainapp.serializers import DeviceSerializer
from mainapp.services import (DataProcessingService,
                              DeviceCheckService,
                              RepoService,
                              UniqueCheckService,
                              UserCheckService)
from mainapp.utils import Result

logger = logging.getLogger(__name__)


class AbstractMixin(ABC):
    """
    Abstract mixin
    """

    @property
    def model(self) -> ModelBase:
        """
        Model
        """
        raise NotImplementedError

    @property
    def serializer_class(self) -> SerializerMetaclass:
        """
        Serializer class
        """
        raise NotImplementedError

    @property
    def fk_model(self) -> ModelBase:
        """
        Foreign key model
        """
        raise NotImplementedError

    @property
    def fk_name(self) -> str:
        """
        Foreign key model
        """
        raise NotImplementedError

    @property
    def checks_list(self) -> List[str]:
        """
        List of check names
        """
        raise NotImplementedError

    @property
    def pk_name(self) -> str:
        """
        Primary key name
        """
        raise NotImplementedError

    @property
    def model_name(self) -> str:
        """
        Model name
        """
        raise NotImplementedError


class AbstractViewMixin(AbstractMixin):
    """
    Abstract view mixin
    """

    @abstractmethod
    def get(self,
            request: HttpRequest,
            *args,
            **kwargs
            ) -> HttpResponse:
        """
        Abstract GET method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): HttpResponse
        """
        raise NotImplementedError

    @abstractmethod
    def post(self,
             request: HttpRequest,
             *args,
             **kwargs
             ) -> HttpResponse:
        """
        Abstract POST method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): HttpResponse
        """
        raise NotImplementedError

    @abstractmethod
    def put(self,
            request: HttpRequest,
            *args,
            **kwargs
            ) -> HttpResponse:
        """
        Abstract PUT method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): HttpResponse
        """
        raise NotImplementedError

    @abstractmethod
    def delete(self,
               request: HttpRequest,
               *args,
               **kwargs
               ) -> HttpResponse:
        """
        Abstract DELETE method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): HttpResponse
        """
        raise NotImplementedError


class LoggingMixin(AbstractMixin):
    """
    Logging mixin
    """

    def create_log(self, request: HttpRequest, data: dict) -> None:
        """
        Logging for add views

        Args:
            request (HttpRequest): Request
            data (dict): Log data (add payload)
        """
        logger.info({
            'time': datetime.now(),
            'user': request.user.username,
            'action': 'add',
            'model_name': self.model_name,
            'new_data': data
        })

    def update_log(self,
                   request: HttpRequest,
                   old_data: dict,
                   data: dict
                   ) -> None:
        """
        Logging for update views

        Args:
            request (HttpRequest): Request
            old_data (dict): Old data (for checking difference)
            data (dict): Log data (update payload)
        """
        logger.info({
            'time': datetime.now(),
            'user': request.user.username,
            'action': 'update',
            'model_name': self.model_name,
            'new_data': data,
            'old_data': old_data
        })

    def delete_log(self, request: HttpRequest, obj_name: str) -> None:
        """
        Logging for delete views

        Args:
            request (HttpRequest): Request
            obj_name (str): Deleting object name
        """
        logger.info({
            'time': datetime.now(),
            'user': request.user.username,
            'action': 'delete',
            'model_name': self.model_name,
            'object_name': obj_name
        })


class ChecksMixin(AbstractMixin):
    """
    Checks mixin for adding, updating and deleting instances
    """
    permission_alert: str = 'Permission alert, changes are prohibited'
    units_exist_message: str = 'There are no such units in this rack'
    units_busy_message: str = 'These units are busy'

    def _check_user(self, request: HttpRequest, pk: int) -> Result:
        """
        Checks user permission
        Checking if there is a group named department
        in the list of user groups that matches
        the model object belonging to the area
        of responsibility of the department (by primary keys)
        Does not allow you to change the data assigned to another department

        Args:
            request (HttpRequest): Request
            pk (int): Primary key

        Returns:
            Result.sucsess == False (Result): Action prohibited
                (read Result.message)
            Result.sucsess == True (Result): Action allowed
        """
        user_groups = list(request.user.groups.values_list('name', flat=True))
        if not UserCheckService.check_for_groups(user_groups, pk, self.model):
            return Result(False, self.permission_alert)
        return Result(True, 'Success')

    def _check_unique(self,
                      pk: int,
                      fk: Optional[int],
                      model: ModelBase,
                      fk_model: ModelBase,
                      instance_name: Optional[str],
                      key_name: Optional[str]
                      ) -> Result:
        """
        Checks for unique names (only for Site|Building|Room)

        Args:
            pk (int): Primary key
            fk (int): Foreign key (optional for update)
            model (ModelBase): Model
            fk_model (ModelBase): Foreign key model
            instance_name (str): Instance name (optional for update and delete)
            key_name (str): Key name (optional for add and update)

        Returns:
            Result.sucsess == False (Result): Action prohibited
                (read Result.message)
            Result.sucsess == True (Result): Action allowed
        """
        # For rack properties changes (name staing the same)
        if instance_name != key_name:
            if fk is None:
                names_list = UniqueCheckService \
                    .get_unique_object_names_list(pk, model)
            else:
                names_list = UniqueCheckService \
                    .get_unique_object_names_list(fk, fk_model)
            if key_name in names_list:
                return Result(False,
                              f"A {self.model_name} "
                              f"with the same name already exists")
            return Result(True, 'Success')
        return Result(True, 'Success')

    def _check_device_for_add(self, pk: int, data: dict) -> Result:
        """
        Checks is it possible to add a new device

        Args:
            pk (int): Primary key
            data (dict): Add payload data

        Returns:
            Result.sucsess == False (Result): Action prohibited
                (read Result.message)
            Result.sucsess == True (Result): Action allowed
        """
        if (first_unit := data.get('first_unit')) is None:
            return Result(False, "Missing required data - first_unit")
        if (last_unit := data.get('last_unit')) is None:
            return Result(False, "Missing required data - last_unit")
        if (frontside_location := data.get('frontside_location')) is None:
            return Result(False, "Missing required data - frontside_location")
        new_units = DeviceCheckService \
            .get_new_units(first_unit, last_unit)
        # Check units exists
        if DeviceCheckService.check_unit_exist(new_units, pk):
            return Result(False, self.units_exist_message)
        # Check units busy
        if DeviceCheckService \
            .check_unit_busy(frontside_location,
                             pk,
                             new_units,
                             old_units=None):
            return Result(False, self.units_busy_message)
        return Result(True, 'Success')

    def _check_device_for_update(self, pk: int, data: dict) -> Result:
        """
        Checks is it possible to replace an existing device

        Args:
            pk (int): Primary key
            data (dict): Add payload data

        Returns:
            Result.sucsess == False (Result): Action prohibited
                (read Result.message)
            Result.sucsess == True (Result): Action allowed
        """
        if (first_unit := data.get('first_unit')) is None:
            return Result(False, "Missing required data - first_unit")
        if (last_unit := data.get('last_unit')) is None:
            return Result(False, "Missing required data - last_unit")
        if (frontside_location := data.get('frontside_location')) is None:
            return Result(False, "Missing required data - frontside_location")
        rack_id = RepoService.get_device_rack_id(pk)
        old_units = DeviceCheckService.get_old_units(pk)
        new_units = DeviceCheckService.get_new_units(first_unit, last_unit)
        # Check units exists
        if DeviceCheckService.check_unit_exist(new_units, rack_id):
            return Result(False, self.units_exist_message)
        # Check units busy
        if DeviceCheckService \
            .check_unit_busy(frontside_location,
                             rack_id,
                             new_units,
                             old_units):
            return Result(False, self.units_busy_message)
        return Result(True, 'Success')

    def get_checks(self,
                   request: HttpRequest,
                   pk: int,
                   data: dict,
                   fk: Optional[int] = None,
                   model: Optional[ModelBase] = None,
                   fk_model: Optional[ModelBase] = None,
                   instance_name: Optional[str] = None,
                   key_name: Optional[str] = None,
                   ) -> List[Result]:
        """
        Get a list of check results

        Args:
            request (HttpRequest): Request
            pk (int): Primary key
            data (dict): Add payload data
            fk (int): Foreign key (optional for update)
            model (ModelBase): Model
            fk_model (ModelBase): Foreign key model
            instance_name (str): Instance name (optional for update and delete)
            key_name (str): Key name (optional for add and update)

        Raises:
            ValueError ('check: str must be'
                        'check_user|check_unique|'
                        'check_device_for_add|'
                        'check_device_for_update,'
                        'other checks dont implemented'):
                Check value is not in a list of implemented check names
        Returns:
            check_results_list (list): List of Result objects
        """
        check_results_list: List[Result] = []
        for check in self.checks_list:
            if check == 'check_user':
                check_results_list \
                    .append(self._check_user(request, pk))
            elif check == 'check_unique':
                check_results_list \
                    .append(self._check_unique(pk,
                                               fk,
                                               model,
                                               fk_model,
                                               instance_name,
                                               key_name))
            elif check == 'check_device_for_add':
                check_results_list \
                    .append(self
                            ._check_device_for_add(pk, data))
            elif check == 'check_device_for_update':
                check_results_list \
                    .append(self
                            ._check_device_for_update(pk, data))
            else:
                raise ValueError('check: str must be'
                                 'check_user|check_unique|'
                                 'check_device_for_add|'
                                 'check_device_for_update,'
                                 'other checks dont implemented')
        return check_results_list

    def get_checks_result(self, results_list: List[Result]) -> Result:
        """
        Get the final result of the checks

        Args:
            results_list (list): List of Result objects

        Returns:
            Result.sucsess == False (Result): Action prohibited (final)
                (read Result.message)
            Result.sucsess == True (Result): Action allowed (final)
        """
        for result in results_list:
            if not result.success:
                return result
        return Result(True, 'Success')


class RackListApiViewMixin:
    """
    Racks list API mixin
    """
    queryset: QuerySet = RepoService.get_all_racks()


class DeviceListApiViewMixin:
    """
    Devices list API mixin
    """
    queryset: QuerySet = RepoService.get_all_devices()


class BaseApiMixin(AbstractViewMixin):

    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Get method plug

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): This method not provided
        """
        return Response({"invalid": "GET not allowed"}, status=405)

    def put(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Put method plug

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): This method not provided
        """
        return Response({"invalid": "PUT not allowed"}, status=405)

    def post(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Post method plug

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): This method not provided
        """
        return Response({"invalid": "POST not allowed"}, status=405)

    def delete(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Delete method plug

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): This method not provided
        """
        return Response({"invalid": "DELETE not allowed"}, status=405)


class BaseApiGetMixin(BaseApiMixin):
    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Base get method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Response with data
            Response (HttpResponse): Object with this ID
                does not exist (exception)
        """
        try:
            instance = RepoService.get_instance(self.model, kwargs['pk'])
            serializer = self.serializer_class(instance)
            return Response(serializer.data)
        except self.model.DoesNotExist:
            message = f"{self.model.__name__} with this ID does not exist"
            return Response({"invalid": message}, status=400)


class BaseApiAddMixin(BaseApiMixin,
                      ChecksMixin,
                      LoggingMixin):
    """
    Base api add mixin
    """

    def post(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Base post method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Need fk for post method (exception)
            Response (HttpResponse): Object with this ID
                does not exist (exception)
            Response (HttpResponse): Add not allowed (read result.message)
            Response (HttpResponse): Sucsessfully added
            Response (HttpResponse): Not good data (validation error)
        """
        data = request.data
        # Some data validation
        try:
            pk = data[self.pk_name]
        except KeyError:
            return Response({"invalid": "Need fk for post method"})
        try:
            RepoService.get_instance(self.model, pk)
        except self.model.DoesNotExist:
            message = f"{self.model.__name__} with this ID does not exist"
            return Response({"invalid": message}, status=400)
        # Add username to data
        data['updated_by'] = request.user.username
        key_name = DataProcessingService.get_key_name(data, self.model_name)
        serializer = self.serializer_class(data=data)
        if serializer.is_valid(raise_exception=True):
            # Check for add possibility
            checks = self \
                .get_checks(request,
                            pk=pk,
                            model=self.model,
                            data=data,
                            key_name=key_name)
            result = self.get_checks_result(checks)
            if not result.success:
                return Response({"invalid": result.message}, status=400)
            serializer.save()
            # Log this
            self.create_log(request, data)
            return Response({"sucsess": f"{key_name} sucsessfully added"})
        return Response({"invalid": "Not good data"}, status=400)


class BaseApiUpdateMixin(BaseApiMixin,
                         ChecksMixin,
                         LoggingMixin):
    """
    Base update mixin
    """

    def put(self, request: HttpResponse, *args, **kwargs) -> HttpResponse:
        """
        Base put method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Need id for post method (exception)
            Response (HttpResponse): Object with this ID
                does not exist (exception)
            Response (HttpResponse): Update not allowed (read result.message)
            Response (HttpResponse): Sucsessfully updated
            Response (HttpResponse): Not good data (validation error)
        """
        data = request.data
        # Some data validation
        try:
            pk = data['id']
        except KeyError:
            return Response({"invalid": "Need id for put method"})
        try:
            instance = RepoService.get_instance(self.model, pk)
        except self.model.DoesNotExist:
            message = f"{self.model.__name__} with this ID does not exist"
            return Response({"invalid": message}, status=400)
        # Add fk and username to data
        fk = getattr(RepoService.get_instance(self.model, pk),
                     f"{self.fk_name}_id")
        data[self.fk_name] = fk
        data['updated_by'] = request.user.username
        # Prevent rack amount updating
        data = DataProcessingService.update_rack_amount(data, pk)
        key_name = DataProcessingService.get_key_name(data, self.model_name)
        instance_name = DataProcessingService \
            .get_instance_name(pk, self.model, self.model_name)
        serializer = self.serializer_class(data=data)
        if serializer.is_valid(raise_exception=True):
            # Check for update possibility
            checks = self \
                .get_checks(request,
                            pk=pk,
                            fk=fk,
                            model=self.model,
                            fk_model=self.fk_model,
                            data=data,
                            instance_name=instance_name,
                            key_name=key_name)
            result = self.get_checks_result(checks)
            if not result.success:
                return Response({"invalid": result.message}, status=400)
            # PrimaryKeyRelatedField doesent work for some unnown reason
            id = data.get(self.fk_name)
            data[self.fk_name] = RepoService.get_instance(self.fk_model, id)
            # Update data
            for key, value in data.items():
                setattr(instance, key, value)
            instance.save()
            # Log this
            self.update_log(request, instance.__dict__, data)
            return Response({"sucsess": f"{key_name} sucsessfully updated"})
        return Response({"invalid": "Not good data"}, status=400)


class BaseApiDeleteMixin(BaseApiMixin,
                         ChecksMixin,
                         LoggingMixin):
    """
    Base delete mixin
    """

    def delete(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Base delete method

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Need id for post method (exception)
            Response (HttpResponse): Object with this ID
                does not exist (exception)
            Response (HttpResponse): Delete not allowed (read result.message)
            Response (HttpResponse): Sucsessfully dleted
            Response (HttpResponse): Not good data (validation error)
        """
        data = request.data
        # Some data validation
        try:
            pk = data['id']
        except KeyError:
            return Response({"invalid": "Need id for delete method"})
        try:
            instance = RepoService.get_instance(self.model, pk)
        except self.model.DoesNotExist:
            message = f"{self.model.__name__} with this ID does not exist"
            return Response({"invalid": message}, status=400)
        instance_name = DataProcessingService \
            .get_instance_name(pk, self.model, self.model_name)
        # Check for delete possibility
        checks = self.get_checks(request, pk, data, model=self.model)
        result = self.get_checks_result(checks)
        if not result.success:
            return Response({"invalid": result.message}, status=400)
        instance.delete()
        # Log this
        self.delete_log(request, instance_name)
        return Response({"sucsess": f"{instance_name} sucsessfully deleted"})


class RackDevicesApiMixin(BaseApiMixin):
    """
    Devices for rack mixin
    """

    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Get devices for a single rack

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Response with devices for a single rack
        """
        devices = RepoService.get_devices_for_rack(kwargs['pk'])
        serializaed_data = DeviceSerializer(devices, many=True).data
        return Response(serializaed_data)


class DeviceVendorsApiMixin(BaseApiMixin):
    """
    Device vendors mixin
    """

    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Get device vendors list

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Response with list of device vendors
        """
        device_vendors = RepoService.get_device_vendors()
        return Response({"device_vendors": device_vendors})


class DeviceModelsApiMixin(BaseApiMixin):
    """
    Device models mixin
    """

    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Get device models list

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Response with list of device models
        """
        device_models = RepoService.get_device_models()
        return Response({"device_models": device_models})


class RackVendorsApiMixin(BaseApiMixin):
    """
    Rack vendors mixin
    """

    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Get rack vendors list

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Response with list of device vendors
        """
        rack_vendors = RepoService.get_rack_vendors()
        return Response({"rack_vendors": rack_vendors})


class RackModelsApiMixin(BaseApiMixin):
    """
    Rack models mixin
    """

    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Get rack models list

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Response with list of rack models
        """
        rack_models = RepoService.get_rack_models()
        return Response({"rack_models": rack_models})


class RegionListApiMixin:
    """
    Regions list mixin
    """

    queryset: QuerySet = RepoService.get_all_regions()


class DepartmentListApiMixin:
    """
    Departments list mixin
    """

    queryset: QuerySet = RepoService.get_all_departments()


class SiteListApiMixin:
    """
    Sites list mixin
    """

    queryset: QuerySet = RepoService.get_all_sites()


class BuildingListApiMixin:
    """
    Buildings list mixin
    """

    queryset: QuerySet = RepoService.get_all_buildings()


class RoomListApiMixin:
    """
    Rooms list mixin
    """

    queryset: QuerySet = RepoService.get_all_rooms()


class UserApiMixin(BaseApiMixin):
    """
    User mixin
    """

    def get(self, request: HttpRequest, *args, **kwargs) -> HttpResponse:
        """
        Get username

        Args:
            request (HttpRequest): Request
            *args: Args
            **kwargs: Kwargs

        Returns:
            Response (HttpResponse): Response with username
        """
        return Response({"user": request.user.username})
