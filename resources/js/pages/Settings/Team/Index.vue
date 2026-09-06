<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Copy, Plus, UserCog, KeyRound, RotateCcw, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import EditMemberDialog from '@/components/team/EditMemberDialog.vue';
import InviteMemberDialog from '@/components/team/InviteMemberDialog.vue';
import SetPasswordDialog from '@/components/team/SetPasswordDialog.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useTranslations } from '@/composables/useTranslations';
import type { RoleOption, TeamInvitation, TeamMember } from '@/types/team';
import type { SharedProps } from '@/types';

/**
 * Team (nav's "System · Team", capability `members.view`/`members.manage`)
 * — the tenant's own self-service member management, grouped under Settings
 * rather than a page of its own (see Web\TeamController's own docblock).
 * Every write goes through the same App\Actions\Members\* the platform-admin
 * backoffice's own member management already uses.
 */
const props = defineProps<{
    members: TeamMember[];
    removedMembers: TeamMember[];
    invitations: TeamInvitation[];
    roleOptions: RoleOption[];
    currentUserId: string | null;
}>();

const page = usePage<SharedProps>();
const { t } = useTranslations();

const roleLabel = (value: string): string => props.roleOptions.find((r) => r.value === value)?.label ?? value;

const inviteOpen = ref(false);
const editingMember = ref<TeamMember | null>(null);
const settingPasswordFor = ref<TeamMember | null>(null);

function isSelf(member: TeamMember): boolean {
    return member.user_id === props.currentUserId;
}

async function removeMember(member: TeamMember): Promise<void> {
    const ok = await confirmDialog({
        title: t('Remove :name', { name: member.name }),
        description: t('Remove :name from the team? This can be undone from the Removed list below.', { name: member.name }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/settings/team/${member.id}`, { preserveScroll: true });
}

function restoreMember(member: TeamMember): void {
    router.post(`/settings/team/${member.id}/restore`, {}, { preserveScroll: true });
}

async function cancelInvitation(invitation: TeamInvitation): Promise<void> {
    const ok = await confirmDialog({
        title: t('Cancel invitation'),
        description: t('Cancel the invitation to :email?', { email: invitation.email }),
        tone: 'danger',
    });
    if (!ok) return;

    router.delete(`/settings/team/invitations/${invitation.id}`, { preserveScroll: true });
}

const copied = ref(false);

async function copyInviteLink(): Promise<void> {
    const link = page.props.flash.inviteLink;
    if (!link) return;

    try {
        await navigator.clipboard.writeText(link);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard API blocked (sandboxed/embedded preview) — the link is
        // still shown in the banner below for a manual copy.
    }
}
</script>

<template>
    <AppLayout :title="t('Team')">
        <div class="space-y-5">
            <PageHeader :title="t('Team')" :description="t('Manage who has access to this organisation.')">
                <template #actions>
                    <Button size="sm" @click="inviteOpen = true">
                        <Plus class="size-3.5" :stroke-width="1.5" />
                        {{ t('Invite') }}
                    </Button>
                </template>
            </PageHeader>

            <div
                v-if="page.props.flash.inviteLink"
                class="flex flex-wrap items-center gap-3 border border-success/30 bg-success/10 px-4 py-3 text-sm"
            >
                <span class="text-foreground">{{ t('Invite link created — share it with the invitee yourself:') }}</span>
                <code class="min-w-0 flex-1 truncate text-xs text-muted-foreground">{{ page.props.flash.inviteLink }}</code>
                <Button variant="outline" size="sm" @click="copyInviteLink">
                    <Copy class="size-3.5" :stroke-width="1.5" />
                    {{ copied ? t('Copied') : t('Copy') }}
                </Button>
            </div>

            <!-- Members -->
            <div class="overflow-hidden border border-border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[56rem] text-xs">
                        <thead class="border-b border-border bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Name') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Email') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Roles') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">{{ t('Status') }}</th>
                                <th scope="col" class="px-4 py-2.5 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="member in members"
                                :key="member.id"
                                class="border-b border-border transition-colors last:border-b-0 hover:bg-muted/40"
                            >
                                <td class="px-4 py-3 font-medium text-foreground">
                                    {{ member.name }}
                                    <span v-if="isSelf(member)" class="text-2xs text-muted-foreground">{{ t('(you)') }}</span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ member.email }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ member.roles.map(roleLabel).join(', ') }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge :variant="member.status === 'active' ? 'success' : 'warning'">
                                        {{ member.status === 'active' ? t('Active') : t('Suspended') }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            type="button"
                                            class="p-1 text-muted-foreground hover:text-foreground"
                                            :title="t('Manage roles')"
                                            @click="editingMember = member"
                                        >
                                            <UserCog class="size-3.5" :stroke-width="1.5" />
                                        </button>
                                        <button
                                            v-if="!isSelf(member)"
                                            type="button"
                                            class="p-1 text-muted-foreground hover:text-foreground"
                                            :title="t('Set password')"
                                            @click="settingPasswordFor = member"
                                        >
                                            <KeyRound class="size-3.5" :stroke-width="1.5" />
                                        </button>
                                        <button
                                            v-if="!isSelf(member)"
                                            type="button"
                                            class="p-1 text-muted-foreground hover:text-destructive"
                                            :title="t('Remove')"
                                            @click="removeMember(member)"
                                        >
                                            <X class="size-3.5" :stroke-width="1.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="members.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                                    {{ t('No team members yet.') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pending invitations -->
            <div v-if="invitations.length" class="border border-border bg-card">
                <div class="border-b border-border px-4 py-3">
                    <h3 class="text-sm font-semibold">{{ t('Pending invitations') }}</h3>
                </div>
                <ul class="divide-y divide-border">
                    <li v-for="invitation in invitations" :key="invitation.id" class="flex items-center justify-between gap-3 px-4 py-3 text-xs">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-foreground">{{ invitation.email }}</p>
                            <p class="text-2xs text-muted-foreground">{{ invitation.roles.map(roleLabel).join(', ') }}</p>
                        </div>
                        <Button variant="outline" size="sm" @click="cancelInvitation(invitation)">{{ t('Cancel') }}</Button>
                    </li>
                </ul>
            </div>

            <!-- Removed members -->
            <div v-if="removedMembers.length" class="border border-border bg-card">
                <div class="border-b border-border px-4 py-3">
                    <h3 class="text-sm font-semibold">{{ t('Removed') }}</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ t('Restored members come back suspended, pending a role review.') }}
                    </p>
                </div>
                <ul class="divide-y divide-border">
                    <li v-for="member in removedMembers" :key="member.id" class="flex items-center justify-between gap-3 px-4 py-3 text-xs">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-foreground">{{ member.name }}</p>
                            <p class="text-2xs text-muted-foreground">{{ member.email }}</p>
                        </div>
                        <Button variant="outline" size="sm" @click="restoreMember(member)">
                            <RotateCcw class="size-3.5" :stroke-width="1.5" />
                            {{ t('Restore') }}
                        </Button>
                    </li>
                </ul>
            </div>
        </div>

        <InviteMemberDialog :open="inviteOpen" :role-options="roleOptions" @close="inviteOpen = false" />
        <EditMemberDialog
            :open="editingMember !== null"
            :member="editingMember"
            :role-options="roleOptions"
            :is-self="editingMember !== null && isSelf(editingMember)"
            @close="editingMember = null"
        />
        <SetPasswordDialog :open="settingPasswordFor !== null" :member="settingPasswordFor" @close="settingPasswordFor = null" />
    </AppLayout>
</template>
