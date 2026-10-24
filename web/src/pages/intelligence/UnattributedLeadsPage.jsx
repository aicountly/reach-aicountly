import { Navigate, useSearchParams } from 'react-router-dom';
import { conversionsHref } from '../leads/leadLinks';

/**
 * The unattributed list is the conversions list with attributed=0, filtered on
 * the server with the overview's own predicate (it used to filter the 50
 * newest conversions in the browser). Old links keep working and keep any
 * other params they carry.
 */
export default function UnattributedLeadsPage() {
  const [params] = useSearchParams();
  return <Navigate replace to={conversionsHref({ ...Object.fromEntries(params), attributed: '0' })} />;
}
