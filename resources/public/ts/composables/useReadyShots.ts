import { type Ref, ref, watch } from 'vue'

/** Shots seen working, and shots that finished since without being opened. */
const WORKING_KEY = 'shots-working'
const READY_KEY = 'shots-ready-unseen'

function read(storage: () => Storage, key: string): Set<string> {
  try {
    return new Set(JSON.parse(storage().getItem(key) ?? '[]') as string[])
  } catch {
    return new Set()
  }
}

function write(storage: () => Storage, key: string, ids: Set<string>) {
  try {
    storage().setItem(key, JSON.stringify([...ids]))
  } catch {
    // Without storage the marks only last while the page is open.
  }
}

/**
 * The shots that finished drawing or rendering while another shot was open
 * and have not been opened since, so the shot list can mark them ready.
 * Opening a shot clears its mark.
 */
export function useReadyShots(shots: Ref<{ id: string; busy: boolean }[]>, currentId: Ref<string | undefined>) {
  const working = read(() => sessionStorage, WORKING_KEY)
  const ready = ref(read(() => localStorage, READY_KEY))

  const save = () => {
    write(() => sessionStorage, WORKING_KEY, working)
    write(() => localStorage, READY_KEY, ready.value)
  }

  watch(
    [shots, currentId],
    ([list, current]) => {
      const next = new Set(ready.value)

      for (const shot of list) {
        if (shot.busy) {
          working.add(shot.id)
        } else if (working.delete(shot.id) && shot.id !== current) {
          next.add(shot.id)
        }
      }

      if (current) next.delete(current)

      ready.value = next
      save()
    },
    { immediate: true, deep: true },
  )

  return ready
}
