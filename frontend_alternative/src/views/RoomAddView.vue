<template>
  <div class="min-h-screen">
    <div class="container px-4 mx-auto justify-between pl-8 font-sans font-thin text-xl">
      <TheMessage :messageProps="messageProps"/>
    </div>
    <div class="container px-4 mx-auto justify-between pl-8 font-sans font-light text-sm">
      <RoomForm
        :formProps="formProps"
        v-on:on-submit="submitForm"
      />
    </div>
  </div>
</template>

<script>
import RoomForm from '@/components/RoomForm.vue';
import TheMessage from '@/components/TheMessage.vue';
import {getResponseMessage, postObject} from '@/api';
import {RESPONSE_STATUS} from "@/constants";

export default {
  name: 'RoomAddView',
  components: {
    RoomForm,
    TheMessage
  },
  data() {
    return {
      formProps: {
        name: '',
        buildingFloor: '',
        description: '',
        numberOfRackSpaces: null,
        area: null,
        responsible: '',
        coolingSystem: 'Centralized',
        fireSuppressionSystem: 'Centralized',
        accessIsOpen: false,
        hasRaisedFloor: false
      },
      messageProps: {
        message: '',
        success: false,
      },
    };
  },
  methods: {
    /**
     * Submit room form
     * @param {Object} form Room form
     */
    async submitForm(form) {
      const formData = {
        name: form.name,
        building_floor: form.buildingFloor,
        description: form.description,
        number_of_rack_spaces: form.numberOfRackSpaces,
        area: form.area,
        responsible: form.responsible,
        cooling_system: form.coolingSystem,
        fire_suppression_system: form.fireSuppressionSystem,
        access_is_open: form.accessIsOpen,
        has_raised_floor: form.hasRaisedFloor,
        building_id: parseInt(this.$route.params.building_id)
      };
      const response = await postObject('room', formData);
      if (response.status === RESPONSE_STATUS.CREATED) {
        this.messageProps.success = true;
        this.messageProps.message = `Room ${response.data.data.name} added successfully`;
      } else {
        this.messageProps.success = false;
        this.messageProps.message = getResponseMessage(response);
      }
      window.scrollTo({top: 0, behavior: 'smooth'});
    },
  }
};
</script>
