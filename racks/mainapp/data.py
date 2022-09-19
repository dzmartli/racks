"""
Some data for business logic
"""
from typing import List


class ReportHeaders:
    """
    Headers for report tables
    """

    devices_header_list: List[str] = [
        'Device number',
        'Status',
        'Vendor',
        'Model',
        'Serial number',
        'Description',
        'Project',
        'Ownership',
        'Financially responsible',
        'Inventory number',
        'Responsible',
        'Fixed asset',
        'Link to docs',
        'First unit',
        'Last unit',
        'Installed on the front',
        'Device type',
        'Hostname',
        'IP-address',
        'Stack/Reserve',
        'Port capacity',
        'Software version',
        'Socket type',
        'Power requirement (W)',
        'Voltage (V)',
        'AC/DC',
        'Updated by',
        'Updated at',
        'Rack',
        'Room',
        'Building',
        'Site',
        'Department',
        'Region',
        'Link to device card',
    ]
    racks_header_list: List[str] = [
        'Rack number',
        'Rack name',
        'Rack amount (units)',
        'Vendor',
        'Model',
        'Description',
        'Numbering from bottom to top',
        'Responsible',
        'Financially responsible',
        'Inventory number',
        'Fixed asset',
        'Link to docs',
        'Row',
        'Place',
        'Rack height (mm)',
        'Rack width (mm)',
        'Rack depth (mm)',
        'Useful rack width (inches)',
        'Useful rack depth (mm)',
        'Execution variant',
        'Construction',
        'Location type',
        'Max load (kilo)',
        'Free power sockets',
        'Free UPS power sockets',
        'External power backup supply system',
        'Active ventilation',
        'TOTAL power consumption',
        'Updated by',
        'Updated at',
        'Room',
        'Building',
        'Site',
        'Department',
        'Region',
        'Link to device card',
    ]
