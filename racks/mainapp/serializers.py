from rest_framework import serializers
from rest_framework.fields import SerializerMethodField
from mainapp.models import Rack, Device
from mainapp.services import DataProcessingService
from django.db.models.base import ModelBase
from typing import List


class RackSerializer(serializers.ModelSerializer):
    total_power_w: SerializerMethodField = serializers \
        .SerializerMethodField('get_total_power_w')

    def get_total_power_w(self, obj: Rack) -> int:
        return DataProcessingService.get_devices_power_w_sum(obj.id)

    class Meta:
        model: ModelBase = Rack
        fields: List = [
            'id',
            'rack_name',
            'rack_amount',
            'rack_vendor',
            'rack_model',
            'rack_description',
            'numbering_from_bottom_to_top',
            'responsible',
            'rack_financially_responsible_person',
            'rack_inventory_number',
            'fixed_asset',
            'link',
            'row',
            'place',
            'rack_height',
            'rack_width',
            'rack_depth',
            'rack_unit_width',
            'rack_unit_depth',
            'rack_type',
            'rack_frame',
            'rack_palce_type',
            'max_load',
            'power_sockets',
            'power_sockets_ups',
            'external_ups',
            'cooler',
            'total_power_w',
            'updated_by',
            'updated_at',
            'room_id'
        ]


class DeviceSerializer(serializers.ModelSerializer):

    class Meta:
        model: ModelBase = Device
        fields: List = [
            'id',
            'first_unit',
            'last_unit',
            'frontside_location',
            'status',
            'device_type',
            'device_vendor',
            'device_model',
            'device_hostname',
            'ip',
            'device_stack',
            'ports_amout',
            'version',
            'power_type',
            'power_w',
            'power_v',
            'power_ac_dc',
            'device_serial_number',
            'device_description',
            'project',
            'ownership',
            'responsible',
            'financially_responsible_person',
            'device_inventory_number',
            'fixed_asset',
            'link',
            'updated_by',
            'updated_at',
            'rack_id'
        ]
