<script setup lang="ts">
withDefaults(defineProps<{ inverse?: boolean }>(), { inverse: false });
</script>

<template>
    <span
        class="text-[27px] leading-none font-extrabold tracking-[-1.5px] whitespace-nowrap"
        >active<span
            :class="
                inverse
                    ? 'text-[#b9a99b]'
                    : 'text-[#88776a] dark:text-[#c2afa0]'
            "
            >fit.</span
        ></span
    >
</template>
