<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import { locale } from '../spa/locale';

const props = defineProps<{ modelValue: string; label: string; dir?: 'rtl' | 'ltr' }>();
const showSource = ref(false);
const source = ref(props.modelValue);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const copy = (ar: string, en: string) => locale.value === 'ar' ? ar : en;

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Link.configure({ openOnClick: false, autolink: false, protocols: ['http', 'https', 'mailto'] }),
        Image.configure({ allowBase64: false }),
        Table.configure({ resizable: false }),
        TableRow, TableCell, TableHeader,
    ],
    onUpdate: ({ editor: current }) => emit('update:modelValue', current.getHTML()),
});

watch(() => props.modelValue, value => {
    source.value = value;
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value || '', false);
    }
});

function updateSource(value: string) {
    source.value = value;
    emit('update:modelValue', value);
}

onBeforeUnmount(() => editor.value?.destroy());

function setLink() {
    const href = window.prompt(copy('رابط HTTPS', 'HTTPS link'), 'https://');
    if (!href) return;
    editor.value?.chain().focus().extendMarkRange('link').setLink({ href }).run();
}

function setImage() {
    const src = window.prompt(copy('رابط صورة HTTPS', 'HTTPS image URL'), 'https://');
    if (!src) return;
    editor.value?.chain().focus().setImage({ src }).run();
}
</script>

<template>
    <div class="content-editor" :dir="props.dir">
        <span class="admin-label">{{ label }}</span>
        <div class="content-editor-toolbar" role="toolbar" :aria-label="copy('أدوات المحرر', 'Editor tools')">
            <button type="button" :aria-pressed="editor?.isActive('bold')" @click="editor?.chain().focus().toggleBold().run()"><strong>B</strong></button>
            <button type="button" :aria-pressed="editor?.isActive('italic')" @click="editor?.chain().focus().toggleItalic().run()"><em>I</em></button>
            <button type="button" :aria-pressed="editor?.isActive('heading', { level: 2 })" @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()">H2</button>
            <button type="button" :aria-pressed="editor?.isActive('heading', { level: 3 })" @click="editor?.chain().focus().toggleHeading({ level: 3 }).run()">H3</button>
            <button type="button" :aria-pressed="editor?.isActive('bulletList')" @click="editor?.chain().focus().toggleBulletList().run()">{{ copy('قائمة', 'Bullets') }}</button>
            <button type="button" :aria-pressed="editor?.isActive('orderedList')" @click="editor?.chain().focus().toggleOrderedList().run()">{{ copy('مرقمة', 'Numbered') }}</button>
            <button type="button" :aria-pressed="editor?.isActive('blockquote')" @click="editor?.chain().focus().toggleBlockquote().run()">{{ copy('اقتباس', 'Quote') }}</button>
            <button type="button" @click="setLink">{{ copy('رابط', 'Link') }}</button>
            <button type="button" @click="setImage">{{ copy('صورة', 'Image') }}</button>
            <button type="button" @click="editor?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()">{{ copy('جدول', 'Table') }}</button>
            <button type="button" @click="editor?.chain().focus().undo().run()">{{ copy('تراجع', 'Undo') }}</button>
            <button type="button" @click="editor?.chain().focus().redo().run()">{{ copy('إعادة', 'Redo') }}</button>
        </div>
        <EditorContent v-show="!showSource" :editor="editor" class="content-editor-surface" />
        <textarea v-if="showSource" v-model="source" class="content-editor-source" dir="ltr" spellcheck="false"
            :aria-label="copy('كود HTML', 'HTML source')" @input="updateSource(source)"></textarea>
        <button type="button" class="content-source-toggle" :aria-pressed="showSource" @click="showSource = !showSource">
            {{ showSource ? copy('العودة إلى المحرر المرئي', 'Back to the visual editor') : copy('كود HTML', 'HTML source') }}
        </button>
        <p class="content-note">{{ copy('يُعقّم الخادم هذا المحتوى قبل الحفظ. الصور عبر روابط HTTPS فقط.', 'The server sanitizes this content before saving. Images use HTTPS URLs only.') }}</p>
    </div>
</template>
