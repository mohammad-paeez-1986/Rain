import React, { useState } from "react";
import AutoLayout from "@/Layouts";
import { Head, Link } from "@inertiajs/inertia-react";
import { Button, Card, Collapse, Container, Form, InputGroup, Modal, Nav, Navbar, Stack } from "react-bootstrap";
import { Assignment, MapOutlined, CalendarMonthOutlined, EventAvailable, EventNote, ContentCopy, FileDownload, KeyboardArrowDown } from "@mui/icons-material";
import { When } from "react-if";
import NavBadge from '@/Components/NavBadge';
import { toast } from "react-toastify";
import { useAuthorization } from "@/Hooks";
import MapView from "./MapView";
import classNames from "classnames";


function Index({ auth, counters, subscriptionLink }) {
    const { can } = useAuthorization();

    const [subscriptionModal, setSubscriptionModal] = useState(false);
    const [isCollapseOpen, setIsCollapseOpen] = useState(false);

    const toggleCollapseOpen = () => setIsCollapseOpen(open => !open);

    const showSubscriptionModal = () => setSubscriptionModal(true);
    const hideSubscriptionModal = () => setSubscriptionModal(false);

    const copySubscriptionLink = () => {
        navigator.clipboard.writeText(subscriptionLink);
        toast.success('Link copied to clipboard', { autoHide: 2e3 });
    }

    return (
        <>
            <Head title="Audits" />


            <Navbar bg="light" expand="lg">
                <Container fluid>
                    <Navbar.Toggle aria-controls="navbarScroll" />
                    <Navbar.Collapse id="navbarScroll">
                        <Nav
                            className="me-auto my-2 my-lg-0"
                            style={{ maxHeight: "100px" }}
                            navbarScroll
                        >
                            <Nav.Link as={Link} href={route("audits.index")} className='align-middle'>
                                <Stack direction='horizontal' gap={1}>
                                    <Assignment fontSize='small' />
                                    Audits List
                                </Stack>
                            </Nav.Link>
                            <Nav.Link as={Link} href={route("audits.index", { filters: { status: 'assigned' } })} className='align-middle'>
                                <Stack direction='horizontal' gap={1}>
                                    <EventAvailable fontSize='small' />
                                    Assigned Audits
                                </Stack>
                                {/* <When condition={counters['assigned'] && counters['assigned'] > 0}>{() => (
                                    <NavBadge bg="">{counters['assigned']}</NavBadge>
                                )}</When> */}
                            </Nav.Link>
                            <When condition={can('audits.map')}>
                                <Nav.Link active>
                                    <Stack direction='horizontal' gap={1}>
                                        <MapOutlined fontSize='small' />
                                        Map View
                                    </Stack>
                                </Nav.Link>
                            </When>
                            <When condition={can('audits.calendar')}>
                                <Nav.Link as={Link} href={route('audits.calendar')}>
                                    <Stack direction='horizontal' gap={1}>
                                        <CalendarMonthOutlined fontSize='small' />
                                        Calendar View
                                    </Stack>
                                </Nav.Link>
                            </When>
                        </Nav>
                        <div className="flex flex-wrap gap-1">
                            <Button onClick={showSubscriptionModal} variant="outline-success" className="flex items-center gap-1">
                                <EventNote /> Subscribe to calendar
                            </Button>
                        </div>
                    </Navbar.Collapse>
                </Container>
            </Navbar>
            <Card>
                <Card.Body className='p-0'>
                    <MapView />
                </Card.Body>
            </Card>
        </>
    );
}

Index.layout = (page) => (
    <AutoLayout header="Audits" children={page} />
);

export default Index;
