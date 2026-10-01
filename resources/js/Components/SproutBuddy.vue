<script setup lang="ts">
defineProps<{ happy?: boolean }>();
</script>
<template>
 <svg class="sprout-buddy" :class="{happy}" viewBox="0 0 100 104" fill="none" aria-hidden="true" focusable="false">
  <ellipse cx="50" cy="95" rx="28" ry="5" fill="currentColor" opacity=".08"/>
  <g class="sprout-leaves">
   <path d="M50 55V29" stroke="#4D876A" stroke-width="4" stroke-linecap="round"/>
   <path d="M49 37C28 39 21 29 25 17C42 14 53 23 49 37Z" fill="#92CAA2" stroke="#4D876A" stroke-width="2"/>
   <path d="M51 30C51 14 65 8 77 13C78 28 65 36 51 30Z" fill="#BADCA4" stroke="#4D876A" stroke-width="2"/>
   <path d="M32 24L48 35M68 19L53 28" stroke="#4D876A" stroke-width="2" stroke-linecap="round"/>
  </g>
  <path d="M26 57H74L69 82C67 95 33 95 31 82L26 57Z" fill="#ECA58F" stroke="#B87363" stroke-width="2"/>
  <rect x="22" y="48" width="56" height="13" rx="6" fill="#F5BDA9" stroke="#B87363" stroke-width="2"/>
  <path d="M29 53H50" stroke="#FFE9D8" stroke-width="3" stroke-linecap="round"/>
  <g fill="#C96B76" opacity=".45"><ellipse cx="37" cy="77" rx="5" ry="3"/><ellipse cx="63" cy="77" rx="5" ry="3"/></g>
  <g v-if="happy" stroke="#654B49" stroke-width="2.5" stroke-linecap="round"><path d="M38 71L41 68L44 71M56 71L59 68L62 71"/></g>
  <g v-else fill="#654B49"><ellipse cx="41" cy="71" rx="2" ry="2.6"/><ellipse cx="59" cy="71" rx="2" ry="2.6"/></g>
  <path d="M46 78Q50 83 54 78" stroke="#654B49" stroke-width="2" stroke-linecap="round"/>
  <g class="buddy-sparkles" stroke="#D5A351" stroke-width="2" stroke-linecap="round"><path d="M14 34V42M10 38H18M85 47V55M81 51H89"/><path d="M82 24V28M80 26H84"/></g>
 </svg>
</template>
