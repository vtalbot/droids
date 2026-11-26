import FactoryApp from '@/factory/app';
import type { Store, View } from '@/factory/app';

type Props = { view: View; project: string; store: Store };

export default function Factory({ view, project, store }: Props) {
    return (
        <FactoryApp
            initialStore={store}
            initialView={view}
            initialProject={project}
        />
    );
}
